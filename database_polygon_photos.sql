-- ============================================================
-- Polygon photo uploads (My Assigned Area)
-- Links a field photo to the CENRO polygon it was uploaded for.
-- Run once against reforestation_db. Existing photos keep
-- polygon_id = NULL and are not affected.
-- ============================================================
USE reforestation_db;

ALTER TABLE field_photos
    ADD COLUMN polygon_id INT NULL AFTER user_id,
    ADD INDEX idx_photo_polygon (polygon_id),
    ADD CONSTRAINT fk_photo_polygon
        FOREIGN KEY (polygon_id) REFERENCES polygons(polygon_id) ON DELETE SET NULL;
