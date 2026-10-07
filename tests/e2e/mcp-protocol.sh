#!/usr/bin/env bash
set -euo pipefail
trap 'rc=$?; echo "::error::mcp-protocol.sh failed at line $LINENO: $BASH_COMMAND (exit $rc)" >&2' ERR

BASE_URL="${WP_BASE_URL:-http://localhost:8888}"
ENDPOINT="${BASE_URL}/?rest_route=/mcp/mcp-adapter-default-server"
PROTOCOL_VERSION="${MCP_PROTOCOL_VERSION:-2025-11-25}"
WORKDIR="$(mktemp -d)"
FIXTURE_PATH="mcp-e2e-fixture.json"

cleanup() {
  bash tests/e2e/wp-cli.sh eval '
    foreach ( array( "admin", "mcp_student_a", "mcp_student_b" ) as $login ) {
      $user = get_user_by( "login", $login );
      if ( $user && class_exists( "WP_Application_Passwords" ) ) {
        WP_Application_Passwords::delete_all_application_passwords( $user->ID );
      }
    }
    $file = WP_CONTENT_DIR . "/mcp-e2e-fixture.json";
    if ( file_exists( $file ) ) {
      unlink( $file );
    }
  ' >/dev/null 2>&1 || true
  rm -rf "$WORKDIR"
}
trap cleanup EXIT

fail() {
  echo "::error::$*" >&2
  exit 1
}

wait_for_wordpress() {
  for i in $(seq 1 30); do
    if curl -4 --fail --silent "${BASE_URL}/?rest_route=/" >/dev/null; then
      return 0
    fi
    sleep 2
  done
  fail "WordPress did not become ready."
}

read_json() {
  local json="$1"
  local expr="$2"
  printf '%s' "$json" | jq -er "$expr"
}

init_session() {
  local login="$1"
  local pass="$2"
  local prefix="$3"
  local headers="${WORKDIR}/${prefix}-headers.txt"
  local body="${WORKDIR}/${prefix}-init.json"

  local status
  status="$(curl -4 --silent --show-error -D "$headers" -o "$body" -w '%{http_code}'     -X POST "$ENDPOINT"     --user "${login}:${pass}"     -H 'Content-Type: application/json'     -d "{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"${PROTOCOL_VERSION}","capabilities":{},"clientInfo":{"name":"tutorlms-mcp-e2e","version":"1.0.0"}}}")"

  if [ "$status" != "200" ]; then
    cat "$body" >&2 || true
    fail "MCP initialize for $login failed with HTTP $status"
  fi

  grep -q '"result"' "$body" || fail "MCP initialize for $login did not return result."

  local session
  session="$(awk 'BEGIN{IGNORECASE=1} /^Mcp-Session-Id:/ {gsub("\r","",$2); print $2}' "$headers" | tail -n1)"
  [ -n "$session" ] || fail "MCP initialize for $login did not return Mcp-Session-Id."

  curl -4 --fail --silent --show-error     -X POST "$ENDPOINT"     --user "${login}:${pass}"     -H 'Content-Type: application/json'     -H "Mcp-Session-Id: $session"     -H "MCP-Protocol-Version: $PROTOCOL_VERSION"     -d '{"jsonrpc":"2.0","method":"notifications/initialized"}' >/dev/null

  printf '%s' "$session"
}

mcp_request() {
  local login="$1"
  local pass="$2"
  local session="$3"
  local payload="$4"

  curl -4 --fail --silent --show-error     -X POST "$ENDPOINT"     --user "${login}:${pass}"     -H 'Content-Type: application/json'     -H "Mcp-Session-Id: $session"     -H "MCP-Protocol-Version: $PROTOCOL_VERSION"     -d "$payload"
}

call_ability() {
  local login="$1"
  local pass="$2"
  local session="$3"
  local request_id="$4"
  local ability="$5"
  local params="$6"

  local payload
  payload="$(jq -cn     --argjson id "$request_id"     --arg ability "$ability"     --argjson parameters "$params"     '{jsonrpc:"2.0",id:$id,method:"tools/call",params:{name:"mcp-adapter-execute-ability",arguments:{ability_name:$ability,parameters:$parameters}}}')"

  mcp_request "$login" "$pass" "$session" "$payload"
}

expect_error_response() {
  local response="$1"
  local label="$2"
  if ! printf '%s' "$response" | grep -Eq '"error"|"isError"[[:space:]]*:[[:space:]]*true'; then
    printf '%s\n' "$response" >&2
    fail "$label unexpectedly succeeded."
  fi
}

wait_for_wordpress

bash tests/e2e/wp-cli.sh eval-file /workspace/tests/e2e/setup-fixture.php >/dev/null

fixture="$(curl -4 --fail --silent --show-error "${BASE_URL}/wp-content/${FIXTURE_PATH}")"
[ -n "$fixture" ] || fail "Fixture JSON was empty."

ADMIN_LOGIN="$(read_json "$fixture" '.admin.login')"
ADMIN_PASS="$(read_json "$fixture" '.admin.app_pass')"
STUDENT_A_LOGIN="$(read_json "$fixture" '.student_a.login')"
STUDENT_A_PASS="$(read_json "$fixture" '.student_a.app_pass')"
STUDENT_A_ID="$(read_json "$fixture" '.student_a.id')"
STUDENT_B_ID="$(read_json "$fixture" '.student_b.id')"
COURSE_ID="$(read_json "$fixture" '.course_id')"
DRAFT_COURSE_ID="$(read_json "$fixture" '.draft_course_id')"

# 1. Unauthenticated requests must be rejected.
unauth_status="$(curl -4 -sS -o "${WORKDIR}/unauth.json" -w '%{http_code}'   -X POST "$ENDPOINT"   -H 'Content-Type: application/json'   -d "{"jsonrpc":"2.0","id":0,"method":"initialize","params":{"protocolVersion":"${PROTOCOL_VERSION}","capabilities":{},"clientInfo":{"name":"unauth","version":"1"}}}")"
if [ "$unauth_status" -lt 400 ] || [ "$unauth_status" -ge 500 ]; then
  cat "${WORKDIR}/unauth.json" >&2 || true
  fail "Expected unauthenticated initialize to be rejected with HTTP 4xx, got $unauth_status."
fi

# 2. Malformed JSON must not execute.
malformed_status="$(curl -4 -sS -o "${WORKDIR}/malformed.json" -w '%{http_code}'   -X POST "$ENDPOINT"   --user "${ADMIN_LOGIN}:${ADMIN_PASS}"   -H 'Content-Type: application/json'   -d '{')"
if [ "$malformed_status" -lt 400 ] && ! grep -q '"error"' "${WORKDIR}/malformed.json"; then
  fail "Malformed JSON was not rejected."
fi

# 3. Unsupported protocol revision must return an error, not a successful session.
bad_protocol="$(curl -4 --silent --show-error   -X POST "$ENDPOINT"   --user "${ADMIN_LOGIN}:${ADMIN_PASS}"   -H 'Content-Type: application/json'   -d '{"jsonrpc":"2.0","id":90,"method":"initialize","params":{"protocolVersion":"1900-01-01","capabilities":{},"clientInfo":{"name":"bad-protocol","version":"1"}}}')"

# MCP initialize may reject an unsupported revision or negotiate to one the
# server supports. It must never claim that the bogus requested revision was
# successfully negotiated.
if printf '%s' "$bad_protocol" | grep -Eq '"protocolVersion"[[:space:]]*:[[:space:]]*"1900-01-01"'; then
  printf '%s\n' "$bad_protocol" >&2
  fail "Server accepted an unsupported MCP protocol revision."
fi

ADMIN_SESSION="$(init_session "$ADMIN_LOGIN" "$ADMIN_PASS" admin)"
STUDENT_SESSION="$(init_session "$STUDENT_A_LOGIN" "$STUDENT_A_PASS" student-a)"

# 4. Invalid/missing session handling.
missing_status="$(curl -4 -sS -o "${WORKDIR}/missing-session.json" -w '%{http_code}'   -X POST "$ENDPOINT"   --user "${ADMIN_LOGIN}:${ADMIN_PASS}"   -H 'Content-Type: application/json'   -d '{"jsonrpc":"2.0","id":2,"method":"tools/list","params":{}}')"
[ "$missing_status" = "400" ] || fail "Expected tools/list without session HTTP 400, got $missing_status."

fake_status="$(curl -4 -sS -o "${WORKDIR}/fake-session.json" -w '%{http_code}'   -X POST "$ENDPOINT"   --user "${ADMIN_LOGIN}:${ADMIN_PASS}"   -H 'Content-Type: application/json'   -H 'Mcp-Session-Id: definitely-not-a-session'   -H "MCP-Protocol-Version: $PROTOCOL_VERSION"   -d '{"jsonrpc":"2.0","id":3,"method":"tools/list","params":{}}')"
if [ "$fake_status" -lt 400 ] && ! grep -q '"error"' "${WORKDIR}/fake-session.json"; then
  fail "Invalid session was not rejected."
fi

# 5. Unknown JSON-RPC method.
unknown_method="$(mcp_request "$ADMIN_LOGIN" "$ADMIN_PASS" "$ADMIN_SESSION" '{"jsonrpc":"2.0","id":4,"method":"definitely/not-a-method","params":{}}')"
expect_error_response "$unknown_method" "Unknown JSON-RPC method"

# 6. tools/list exposes MCP Adapter meta-tools.
tools_json="$(mcp_request "$ADMIN_LOGIN" "$ADMIN_PASS" "$ADMIN_SESSION" '{"jsonrpc":"2.0","id":5,"method":"tools/list","params":{}}')"
for tool in mcp-adapter-discover-abilities mcp-adapter-get-ability-info mcp-adapter-execute-ability; do
  printf '%s' "$tools_json" | grep -q "$tool" || fail "tools/list is missing $tool."
done

# 7. Unknown tool.
unknown_tool="$(mcp_request "$ADMIN_LOGIN" "$ADMIN_PASS" "$ADMIN_SESSION" '{"jsonrpc":"2.0","id":6,"method":"tools/call","params":{"name":"does-not-exist","arguments":{}}}')"
expect_error_response "$unknown_tool" "Unknown MCP tool"

# 8. Discover all TutorLMS abilities.
discover_json="$(mcp_request "$ADMIN_LOGIN" "$ADMIN_PASS" "$ADMIN_SESSION" '{"jsonrpc":"2.0","id":7,"method":"tools/call","params":{"name":"mcp-adapter-discover-abilities","arguments":{}}}')"
discover_normalized="$(printf '%s' "$discover_json" | sed 's#\\/#/#g')"
for ability in tutorlms/site-info tutorlms/list-courses tutorlms/get-course tutorlms/get-course-structure tutorlms/get-student-progress; do
  printf '%s' "$discover_normalized" | grep -q "$ability" || fail "MCP discovery is missing $ability."
done

# 9. Data-driven list-courses.
list_json="$(call_ability "$ADMIN_LOGIN" "$ADMIN_PASS" "$ADMIN_SESSION" 10 tutorlms/list-courses '{"page":1,"per_page":20,"status":"publish"}')"
printf '%s' "$list_json" | grep -q 'MCP E2E Published Course' || fail "Published fixture course missing from list-courses."

# 10. Data-driven get-course.
course_json="$(call_ability "$ADMIN_LOGIN" "$ADMIN_PASS" "$ADMIN_SESSION" 11 tutorlms/get-course "{"course_id":$COURSE_ID}")"
printf '%s' "$course_json" | grep -q 'MCP E2E Published Course' || fail "get-course did not return fixture course."
printf '%s' "$course_json" | grep -q 'MCP E2E course content' || fail "get-course did not return fixture content."

# 11. Data-driven course structure.
structure_json="$(call_ability "$ADMIN_LOGIN" "$ADMIN_PASS" "$ADMIN_SESSION" 12 tutorlms/get-course-structure "{"course_id":$COURSE_ID}")"
printf '%s' "$structure_json" | grep -q 'MCP E2E Topic' || fail "Course structure missing fixture topic."
printf '%s' "$structure_json" | grep -q 'MCP E2E Lesson' || fail "Course structure missing fixture lesson."

# 12. Student can read own Tutor progress.
progress_json="$(call_ability "$STUDENT_A_LOGIN" "$STUDENT_A_PASS" "$STUDENT_SESSION" 13 tutorlms/get-student-progress "{"course_id":$COURSE_ID,"user_id":$STUDENT_A_ID}")"
printf '%s' "$progress_json" | grep -q '"available"' || fail "Student progress response did not include availability."
printf '%s' "$progress_json" | grep -q "$COURSE_ID" || fail "Student progress response did not reference fixture course."

# 13. Cross-user progress is forbidden.
cross_user_json="$(call_ability "$STUDENT_A_LOGIN" "$STUDENT_A_PASS" "$STUDENT_SESSION" 14 tutorlms/get-student-progress "{"course_id":$COURSE_ID,"user_id":$STUDENT_B_ID}")"
expect_error_response "$cross_user_json" "Cross-user student progress"

# 14. Invalid ability arguments must be schema-rejected.
bad_args_json="$(call_ability "$ADMIN_LOGIN" "$ADMIN_PASS" "$ADMIN_SESSION" 15 tutorlms/get-course '{"course_id":"not-an-integer"}')"
expect_error_response "$bad_args_json" "Malformed ability arguments"

# 15. Learner list-courses cannot elevate post_status to draft.
student_draft_list="$(call_ability "$STUDENT_A_LOGIN" "$STUDENT_A_PASS" "$STUDENT_SESSION" 16 tutorlms/list-courses '{"status":"draft"}')"
printf '%s' "$student_draft_list" | grep -q 'MCP E2E Published Course' || fail "Learner draft request did not fall back to published courses."
if printf '%s' "$student_draft_list" | grep -q 'MCP E2E Draft Course'; then
  fail "Draft course leaked through learner list-courses."
fi

# 16. Learner cannot read unpublished course or its curriculum.
student_draft_course="$(call_ability "$STUDENT_A_LOGIN" "$STUDENT_A_PASS" "$STUDENT_SESSION" 17 tutorlms/get-course "{"course_id":$DRAFT_COURSE_ID}")"
expect_error_response "$student_draft_course" "Draft course access"
student_draft_structure="$(call_ability "$STUDENT_A_LOGIN" "$STUDENT_A_PASS" "$STUDENT_SESSION" 18 tutorlms/get-course-structure "{"course_id":$DRAFT_COURSE_ID}")"
expect_error_response "$student_draft_structure" "Draft course structure access"

# 17. Session DELETE invalidates the session.
curl -4 --fail --silent --show-error   -X DELETE "$ENDPOINT"   --user "${STUDENT_A_LOGIN}:${STUDENT_A_PASS}"   -H "Mcp-Session-Id: $STUDENT_SESSION"   -H "MCP-Protocol-Version: $PROTOCOL_VERSION" >/dev/null

reuse_status="$(curl -4 -sS -o "${WORKDIR}/reuse.json" -w '%{http_code}'   -X POST "$ENDPOINT"   --user "${STUDENT_A_LOGIN}:${STUDENT_A_PASS}"   -H 'Content-Type: application/json'   -H "Mcp-Session-Id: $STUDENT_SESSION"   -H "MCP-Protocol-Version: $PROTOCOL_VERSION"   -d '{"jsonrpc":"2.0","id":19,"method":"tools/list","params":{}}')"
if [ "$reuse_status" -lt 400 ] && ! grep -q '"error"' "${WORKDIR}/reuse.json"; then
  fail "Deleted MCP session remained usable."
fi

curl -4 --fail --silent --show-error   -X DELETE "$ENDPOINT"   --user "${ADMIN_LOGIN}:${ADMIN_PASS}"   -H "Mcp-Session-Id: $ADMIN_SESSION"   -H "MCP-Protocol-Version: $PROTOCOL_VERSION" >/dev/null

echo "TutorLMS MCP protocol/data/security E2E passed."
