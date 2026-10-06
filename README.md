# TutorLMS MCP

WordPress plugin that exposes Tutor LMS functionality through the WordPress Abilities API so it can be discovered and executed by [WordPress MCP Adapter](https://github.com/WordPress/mcp-adapter).

## Requirements

- WordPress 6.9+
- Tutor LMS
- MCP Adapter

## Initial abilities

- `tutorlms/site-info`
- `tutorlms/list-courses`
- `tutorlms/get-course`
- `tutorlms/get-course-structure`
- `tutorlms/get-student-progress`

All abilities are exposed to MCP only via `meta.mcp.public = true`.

## Installation

1. Install and activate Tutor LMS.
2. Install and activate MCP Adapter.
3. Install this plugin.
4. Connect your MCP client to the MCP Adapter endpoint.
5. Discover abilities and look for the `tutorlms/*` namespace.

## Security

Each ability performs its own WordPress capability check. Read operations currently require a logged-in user with the `read` capability. Future write operations will use stricter Tutor LMS/WordPress capability checks.

## Roadmap

- Course CRUD
- Topic CRUD
- Lesson CRUD
- Quiz CRUD
- Enrollment management
- Student progress and reporting
- Instructor management
- Assignments, Q&A, reviews, announcements
- Tutor LMS Pro-aware adapters
- Tests and CI
