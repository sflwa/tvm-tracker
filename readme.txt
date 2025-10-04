TVM Tracker WordPress Plugin
A robust and feature-rich WordPress plugin designed for users to track their progress through TV shows and movies. Data is synchronized and provided by the Watchmode API, ensuring you always know what you've watched and what's airing next.

Features
V2.0 Database Architecture: Utilizes a highly normalized and optimized local database schema to store static episode data, reducing dependence on repetitive external API calls and greatly improving page load speeds.

Smart Update Scheduling: Automatically checks the Watchmode API once per week for new episodes for ongoing (non-ended) shows, keeping your tracker up-to-date with minimal resource usage.

Advanced Unwatched Queue: Provides separate, chronologically sorted views for:

Upcoming Episodes: Airing today or in the future.

Past Episodes: Episodes you have missed and need to catch up on.

Detailed Progress Tracking: Mark individual episodes as watched/unwatched directly from the show detail page.

Streaming Source Integration: Displays where every episode is available to stream (logo/link) based on the streaming services you have enabled in the plugin settings.

Responsive Views: Easily toggle between visual Poster View and detailed List View on the "My Tracker" page.

Requirements
A functioning WordPress installation.

A valid Watchmode API Key (required for data fetching and population).

Changelog
2.0.0 - Major Schema Refactor & Auto-Update (Current)
Feature: Implemented V2.0 Database Schema (split static episode data, links, and user tracking tables).

Feature: Added Smart Update Check to query the API for new episodes only on ongoing shows (runs weekly).

Enhancement: Completed Unwatched Page functionality with working Upcoming (soonest first) and Past (oldest first) sectional filtering and sorting.

Enhancement: Ensured all front-end filtering logic correctly uses integer IDs, stabilizing source filtering and AJAX calls.

Fix: Corrected logic to ensure the watched/unwatched button is only visible for episodes that have already aired.

Fix: Resolved numerous database synchronization and table access issues caused by the V2.0 migration.

1.1.43 - Initial Feature Release
Feature: Introduced shortcode [tvm_tracker] for display on a custom WordPress page.

Feature: Initial implementation of Search Results, Detail Page, and My Tracker Views.

Feature: Basic Show Tracking (Add/Remove) and Episode Tracking (Watched/Unwatched) via AJAX.

Enhancement: Added custom rewrite rules for clean permalinks (e.g., /my-shows/details/).

Data Source & Credit
This plugin relies on the comprehensive data provided by the Watchmode API.

Data Provider: Watchmode

API Documentation: https://api.watchmode.com/docs/

Please ensure you have entered a valid Watchmode API key in the plugin settings to enable data retrieval and population of the local source tables.