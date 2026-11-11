# REFORESTATION MANAGEMENT FOR CENRO.

## Seedling requests, batches and mortality

The seedling tables are part of `database.sql` (see Setup notes).

- **Field User** - *Seedlings* menu: request seedlings for an assigned area, report dead
  seedlings against the batch they came from, and follow request status
  (Pending, Approved, Rejected, Released). A mortality report has a
  *Request replacement* shortcut.
- **Admin / Manager** - *Seedlings* menu: approve a request (quantity adjustable), reject it
  with a note, or mark it as released. Every release becomes its own numbered batch for the
  area (Batch 1, Batch 2, ...); batches are never merged. *Add Initial Planting* records an
  area planted before the system so its mortality rate can be calculated.
- **Mortality rate** = seedlings reported dead / seedlings released, shown per batch, per
  area and overall. A report cannot exceed the seedlings still alive in its batch.
- Inventory / stock tracking is not included.

## Setup notes

Import **one** file in phpMyAdmin (or `mysql -u root < database.sql`): `database.sql`.
It creates the `reforestation_db` database and every table - users, activity logs,
projects, polygons, field photos and the seedling tables - and adds the default
admin account (`admin` / `Admin@123`; change the password after the first login).
It is safe to run again; it never drops or alters existing data.

The earlier split files (`database_polygons.sql`, `database_polygon_photos.sql`,
`database_seedlings.sql`, `database_api_migration.sql`) were merged into it and removed.

### Maps

Leaflet is loaded from unpkg, then jsDelivr, then cdnjs, then `assets/vendor/leaflet/`.
For maps that still work when public CDNs are blocked, copy Leaflet 1.9.4's `dist` files into
`assets/vendor/leaflet/` (see the README.txt there). Map tiles themselves always need internet.

## Interface

- Brand bar with logo, collapsible menu on phones, and footer on every page.
- Admin / Manager menu: Dashboard, User Management, CENRO Polygons, Photo Reviews, Seedlings
  (Admin also gets Activity Logs). Photo Reviews and Seedlings show a count of items waiting
  for a decision.
- **Dashboards** show the CENRO data overview (polygon totals, mapped hectares, status bars,
  imported formats, recent imported files), then tree-photo and seedling numbers, then the map.
  Field users see the same overview limited to their own areas.
- **Maps** open on satellite imagery, one polygon at a time with the surrounding area darkened.
  Use Previous / Next or the selector to change area; Admin / Manager (and Field Users on
  "My Assigned Area") can switch to *View all polygons*. Tree-photo markers stay clickable on top
  of the boundary. The Street / Satellite choice is remembered in the browser.
