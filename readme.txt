=== TV & Movie Tracker ===
Contributors: sflwa
Tags: movies, tv shows, tracking, watchlist, tmdb, watchmode
Requires at least: 5.8
Tested up to: 6.4
Stable tag: 1.9.7
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

A premium personal media vault for tracking movies and television series with real-time streaming data.

== Description ==

TV & Movie Tracker is a highly specialized WordPress plugin designed for power users to manage their personal media libraries. The plugin utilizes a modular architecture to separate movie and television logic, ensuring stability for long-term collections.

Key Features:
* **Dual-Vault Architecture**: Separate processing for 100+ movies and complex TV series.
* **Real-Time Streaming Data**: Integration with Watchmode to show localized "Where to Watch" links based on user-defined regions and services.
* **Strict Streaming Rules**: Supports subscription-only filtering, primary region locking for paid services, and "Free" service support across VPN regions.
* **Upcoming & TBA Tracking**: Advanced sorting for rumored or undated films, including "X DAYS" countdown badges.
* **Progress Tracking**: Mark movies as watched or drill down into TV series to track individual episode progress.
* **Modular JS/PHP**: Uses a core orchestrator to manage specialized modules for Search, Settings, Movies, and TV.

== Dependencies & Credits ==

This plugin relies on the following third-party services for its core functionality:

* **TMDb (The Movie Database)**: Provides primary movie and TV metadata, including posters, overviews, and IDs. (This product uses the TMDb API but is not endorsed or certified by TMDb.)
* **TVmaze**: Provides detailed episode-level scheduling, summaries, and air dates for television series.
* **Watchmode**: Powers the streaming source discovery, provider logos, and deep-linking to various streaming platforms.


== Installation ==

1. Upload the `tvm-tracker` folder to the `/wp-content/plugins/` directory.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Navigate to the 'My Settings' tab in the app to enter your TMDb and Watchmode API keys.
4. Use the shortcode `[tvm_tracker_app]` on any page to load the application.

== Frequently Asked Questions ==

= Why are some streaming icons missing? =
Icons only appear if the service is enabled in your settings and the movie/episode is available in your primary region for paid services, or any enabled region for free services.

= How do I handle movies without a release date? =
The plugin automatically flags these as "TBA" and moves them to the Upcoming filter.

== Changelog ==

= 2.0.0 =
* NEW: Automation Health dashboard in Admin Settings.
* NEW: Custom "Monthly" cron interval for streaming data refresh.
* FIX: Contextual "Stream Only" logic for TV shows (hides shows with no streamable unwatched episodes).
* FIX: Added "Self-Healing" cron registration to prevent inactive sync events.
* FIX: Improved data integrity by saving Status and Release Date on initial import.

= 1.9.7 =
* Finalized modular split between Movie and TV JS/PHP handlers.
* Implemented strict streaming logic (Rule 1: Paid/Primary, Rule 2: Free/Any).
* Added auto-recovery for global streaming source cache.
* Restored "X DAYS" badges and TBA sorting for movies.
* Fixed modal encoding for special characters in titles.
* Added stylized Dashicon placeholders for missing poster art.

= 1.9.0 =
* Added support for multiple regions (US/CA) in user settings.
* Implemented TV Season "Mark All Watched" functionality.
* Integrated TVmaze for enhanced episode metadata.

= 1.0.0 =
* Initial release with TMDb and Watchmode integration.
