-- ============================================================
-- Reforestation Management Platform (CENRO)
-- Unified database schema  -  run this ONE file on a fresh install
--
--   mysql -u root < database.sql          (or import it in phpMyAdmin)
--
-- Replaces the old split files:
--   database.sql (old), database_polygons.sql,
--   database_polygon_photos.sql, database_seedlings.sql,
--   database_api_migration.sql
--
-- Safe to re-run: every table uses IF NOT EXISTS and the seed rows
-- are only inserted when missing. It never drops or alters data.
--
-- Table order matters because of foreign keys:
--   users -> activity_logs, api_tokens, projects -> polygons
--   -> field_photos -> seedling_requests -> seedling_batches
--   -> seedling_mortality_reports
-- ============================================================

CREATE DATABASE IF NOT EXISTS reforestation_db
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE reforestation_db;

-- ============================================================
-- 1. Users
-- ============================================================
CREATE TABLE IF NOT EXISTS users (
    user_id        INT AUTO_INCREMENT PRIMARY KEY,
    full_name      VARCHAR(150) NOT NULL,
    username       VARCHAR(50)  NOT NULL UNIQUE,
    password       VARCHAR(255) NOT NULL,
    contact_number VARCHAR(30)  NULL,
    role           ENUM('admin', 'manager', 'user') NOT NULL,
    status         ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
    date_created   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    date_updated   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Default Admin account (bcrypt hash from PHP password_hash()).
--   Username: admin      Password: Admin@123
-- Log in, then change the password straight away.
INSERT INTO users (full_name, username, password, contact_number, role, status)
SELECT 'System Administrator', 'admin',
       '$2y$10$UpOq27zYqkJEHrbz0zKUXujvfOOLqBLH.thx4ZFPnPWmJ5.KKAnAS',
       NULL, 'admin', 'active'
WHERE NOT EXISTS (SELECT 1 FROM users WHERE username = 'admin');

-- ============================================================
-- 2. Activity logs (who went where and when; admin oversight)
-- ============================================================
CREATE TABLE IF NOT EXISTS activity_logs (
    log_id      INT AUTO_INCREMENT PRIMARY KEY,
    user_id     INT NULL,
    full_name   VARCHAR(150) NOT NULL,
    role        ENUM('admin', 'manager', 'user') NOT NULL,
    action      VARCHAR(100) NOT NULL,
    module      VARCHAR(100) NOT NULL,
    description TEXT NULL,
    ip_address  VARCHAR(45)  NULL,
    user_agent  VARCHAR(255) NULL,
    status      ENUM('success', 'failed', 'warning') NOT NULL DEFAULT 'success',
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_log_user FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE SET NULL,

    INDEX idx_role (role),
    INDEX idx_action (action),
    INDEX idx_module (module),
    INDEX idx_status (status),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB;

-- ============================================================
-- 3. API tokens (Bearer-token login for the mobile app)
-- ============================================================
CREATE TABLE IF NOT EXISTS api_tokens (
    token_id   INT AUTO_INCREMENT PRIMARY KEY,
    user_id    INT NOT NULL,
    token      VARCHAR(64) NOT NULL UNIQUE,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_token_user FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,

    INDEX idx_token (token),
    INDEX idx_user (user_id)
) ENGINE=InnoDB;

-- ============================================================
-- 4. Projects
-- Minimal placeholder owned by the future Project Management
-- module; polygons.project_id points at it.
-- ============================================================
CREATE TABLE IF NOT EXISTS projects (
    project_id   INT AUTO_INCREMENT PRIMARY KEY,
    project_code VARCHAR(50)  NULL UNIQUE,
    project_name VARCHAR(150) NOT NULL,
    location     VARCHAR(200) NULL,
    status       ENUM('active', 'inactive', 'completed') NOT NULL DEFAULT 'active',
    date_created TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    date_updated TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_projects_status (status)
) ENGINE=InnoDB;

-- Sample project so Admin/Manager can attach a polygon right away. Safe to delete.
INSERT INTO projects (project_code, project_name, location, status)
SELECT 'PRJ-001', 'Manolo Fortich Reforestation Program', 'Manolo Fortich, Bukidnon', 'active'
WHERE NOT EXISTS (SELECT 1 FROM projects WHERE project_code = 'PRJ-001');

-- ============================================================
-- 5. CENRO polygons
-- `geometry` is the full GeoJSON boundary in WGS 84 / EPSG:4326
-- (lon, lat). `original_geometry` is the untouched import in the
-- file's own CRS. centroid_* / bbox_* are map-fitting helpers only.
-- ============================================================
CREATE TABLE IF NOT EXISTS polygons (
    polygon_id       INT AUTO_INCREMENT PRIMARY KEY,

    -- Application-managed information
    polygon_code     VARCHAR(50)  NOT NULL UNIQUE,
    polygon_name     VARCHAR(150) NOT NULL,
    project_id       INT          NULL,
    location         VARCHAR(200) NULL,
    assigned_user_id INT          NULL,
    status           ENUM('active', 'inactive', 'completed') NOT NULL DEFAULT 'active',
    remarks          TEXT         NULL,

    -- Official imported geometry (EPSG:4326)
    geometry         LONGTEXT     NOT NULL,
    geometry_type    VARCHAR(20)  NOT NULL,
    geometry_hash    CHAR(40)     NOT NULL,

    -- Preserved original import
    original_geometry   LONGTEXT NULL,
    original_attributes LONGTEXT NULL,
    original_area_value DECIMAL(18,4) NULL,
    original_area_unit  VARCHAR(20)   NULL,

    -- System-calculated area
    area_sqm      DECIMAL(18,4) NOT NULL DEFAULT 0,
    area_hectares DECIMAL(14,4) NOT NULL DEFAULT 0,

    -- Derived map helpers
    centroid_lat DECIMAL(10,7) NULL,
    centroid_lng DECIMAL(10,7) NULL,
    bbox_min_lat DECIMAL(10,7) NULL,
    bbox_min_lng DECIMAL(10,7) NULL,
    bbox_max_lat DECIMAL(10,7) NULL,
    bbox_max_lng DECIMAL(10,7) NULL,

    -- Source provenance
    source_file   VARCHAR(255) NULL,
    source_format ENUM('shapefile', 'geojson', 'kml') NOT NULL,
    source_crs    VARCHAR(255) NULL,
    source_hash   CHAR(40)     NULL,
    stored_file   VARCHAR(255) NULL,
    feature_index INT          NOT NULL DEFAULT 0,

    imported_by   INT NULL,
    date_imported TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    date_updated  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_polygon_project  FOREIGN KEY (project_id)       REFERENCES projects(project_id) ON DELETE SET NULL,
    CONSTRAINT fk_polygon_assigned FOREIGN KEY (assigned_user_id) REFERENCES users(user_id)       ON DELETE SET NULL,
    CONSTRAINT fk_polygon_importer FOREIGN KEY (imported_by)      REFERENCES users(user_id)       ON DELETE SET NULL,

    INDEX idx_polygons_status   (status),
    INDEX idx_polygons_project  (project_id),
    INDEX idx_polygons_assigned (assigned_user_id),
    INDEX idx_polygons_hash     (geometry_hash),
    INDEX idx_polygons_source   (source_hash)
) ENGINE=InnoDB;

-- ============================================================
-- 6. Field photos (tree photos uploaded by field users)
-- NOTE: the old SQL files never created this table (they only
-- ALTERed it), so a fresh install broke. It is now defined here,
-- with polygon_id built in instead of added later.
-- ============================================================
CREATE TABLE IF NOT EXISTS field_photos (
    photo_id       INT AUTO_INCREMENT PRIMARY KEY,
    user_id        INT NOT NULL,
    polygon_id     INT NULL,
    file_name      VARCHAR(255) NOT NULL,
    original_name  VARCHAR(255) NULL,
    file_size      INT UNSIGNED NULL,

    -- EXIF metadata read from the photo
    exif_latitude  DECIMAL(10,7) NULL,
    exif_longitude DECIMAL(10,7) NULL,
    exif_datetime  DATETIME      NULL,
    camera_make    VARCHAR(100)  NULL,
    camera_model   VARCHAR(100)  NULL,

    -- Admin / Manager review
    status         ENUM('pending', 'confirmed', 'rejected') NOT NULL DEFAULT 'pending',
    reviewed_by    INT NULL,
    reviewed_at    DATETIME NULL,
    review_notes   TEXT NULL,

    uploaded_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_photo_user     FOREIGN KEY (user_id)     REFERENCES users(user_id)       ON DELETE CASCADE,
    CONSTRAINT fk_photo_polygon  FOREIGN KEY (polygon_id)  REFERENCES polygons(polygon_id) ON DELETE SET NULL,
    CONSTRAINT fk_photo_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(user_id)       ON DELETE SET NULL,

    INDEX idx_photo_user    (user_id),
    INDEX idx_photo_polygon (polygon_id),
    INDEX idx_photo_status  (status),
    INDEX idx_photo_uploaded (uploaded_at)
) ENGINE=InnoDB;

-- ============================================================
-- 7. Seedlings: requests, release batches, mortality reports
-- ============================================================

-- A field user asks CENRO for seedlings for one of their areas.
CREATE TABLE IF NOT EXISTS seedling_requests (
    request_id          INT AUTO_INCREMENT PRIMARY KEY,
    user_id             INT NOT NULL,
    polygon_id          INT NULL,
    species             VARCHAR(100) NOT NULL,
    quantity_requested  INT UNSIGNED NOT NULL,
    quantity_approved   INT UNSIGNED NULL,
    reason              ENUM('replacement', 'additional') NOT NULL DEFAULT 'additional',
    date_needed         DATE NULL,
    notes               TEXT NULL,
    mortality_report_id INT NULL,
    status              ENUM('pending', 'approved', 'rejected', 'released') NOT NULL DEFAULT 'pending',
    reviewed_by         INT NULL,
    reviewed_at         DATETIME NULL,
    review_notes        TEXT NULL,
    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_sreq_status (status),
    INDEX idx_sreq_user (user_id),
    INDEX idx_sreq_polygon (polygon_id),
    CONSTRAINT fk_sreq_user    FOREIGN KEY (user_id)     REFERENCES users(user_id)       ON DELETE CASCADE,
    CONSTRAINT fk_sreq_polygon FOREIGN KEY (polygon_id)  REFERENCES polygons(polygon_id) ON DELETE SET NULL,
    CONSTRAINT fk_sreq_review  FOREIGN KEY (reviewed_by) REFERENCES users(user_id)       ON DELETE SET NULL
) ENGINE=InnoDB;

-- One row per release by CENRO. Every release is its own batch; batches
-- are never merged. batch_number counts 1, 2, 3... within each polygon.
-- batch_type 'initial' = a planting that happened before this system.
CREATE TABLE IF NOT EXISTS seedling_batches (
    batch_id     INT AUTO_INCREMENT PRIMARY KEY,
    polygon_id   INT NOT NULL,
    batch_number INT UNSIGNED NOT NULL,
    batch_type   ENUM('release', 'initial') NOT NULL DEFAULT 'release',
    request_id   INT NULL,
    species      VARCHAR(100) NOT NULL,
    quantity     INT UNSIGNED NOT NULL,
    release_date DATE NOT NULL,
    notes        TEXT NULL,
    released_by  INT NULL,
    created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY uq_batch_polygon_number (polygon_id, batch_number),
    INDEX idx_batch_request (request_id),
    CONSTRAINT fk_batch_polygon FOREIGN KEY (polygon_id)  REFERENCES polygons(polygon_id)          ON DELETE CASCADE,
    CONSTRAINT fk_batch_request FOREIGN KEY (request_id)  REFERENCES seedling_requests(request_id) ON DELETE SET NULL,
    CONSTRAINT fk_batch_user    FOREIGN KEY (released_by) REFERENCES users(user_id)                ON DELETE SET NULL
) ENGINE=InnoDB;

-- Seedlings that died, traced to the batch they came from.
-- photo_id is deliberately NOT a foreign key: if the photo is later
-- deleted the report keeps working and simply shows no photo.
CREATE TABLE IF NOT EXISTS seedling_mortality_reports (
    report_id     INT AUTO_INCREMENT PRIMARY KEY,
    user_id       INT NOT NULL,
    polygon_id    INT NOT NULL,
    batch_id      INT NOT NULL,
    quantity_dead INT UNSIGNED NOT NULL,
    cause         ENUM('drought', 'pests_disease', 'flooding', 'animals', 'fire', 'unknown', 'other')
                  NOT NULL DEFAULT 'unknown',
    date_observed DATE NOT NULL,
    notes         TEXT NULL,
    photo_id      INT NULL,
    created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_smort_batch (batch_id),
    INDEX idx_smort_polygon (polygon_id),
    INDEX idx_smort_user (user_id),
    CONSTRAINT fk_smort_user    FOREIGN KEY (user_id)    REFERENCES users(user_id)             ON DELETE CASCADE,
    CONSTRAINT fk_smort_polygon FOREIGN KEY (polygon_id) REFERENCES polygons(polygon_id)       ON DELETE CASCADE,
    CONSTRAINT fk_smort_batch   FOREIGN KEY (batch_id)   REFERENCES seedling_batches(batch_id) ON DELETE CASCADE
) ENGINE=InnoDB;
