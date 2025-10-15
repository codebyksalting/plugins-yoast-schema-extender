# Yoast Schema Extender
A plugin to extend Yoast SEO's schema capabilities - reusable, agency-grade Schema Extender that layers neatly on top of Yoast SEO. It gives you: sane defaults, entity linking, per-page auto-detection, and industry-specific schema pieces you can toggle or extend without fighting Yoast

## Notes
- Settings UI (Options → Schema Extender)
- Yoast dependency check (safe no-op without Yoast)
- “Respect Yoast” compatibility mode (default) + optional Override toggle
- LocalBusiness enrichment that politely steps aside if Yoast Local SEO is active
- CPT→Schema mapping, page-intent detection, Woo Product augmentation
- Entity mentions for LLMs
- Status panel showing which Org fields are coming from Yoast vs. the Extender
- The plugin politely no-ops schema features if Yoast isn’t installed, and shows an admin notice
- All schema mirrors visible content; don’t mark a page FAQPage unless Q&A exists.

Vibe coded with ChatGPT 5
