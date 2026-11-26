-- PRD §7. Import into an existing, empty database (including on shared hosting).
SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE TABLE users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(255) NOT NULL,
    nickname VARCHAR(50) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    profile_image VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE worlds (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    name VARCHAR(100) NOT NULL,
    genre VARCHAR(50) NOT NULL,
    custom_genre VARCHAR(100) NULL,
    era VARCHAR(100) NOT NULL,
    mood VARCHAR(100) NOT NULL,
    description TEXT NOT NULL,
    history LONGTEXT NOT NULL,
    features TEXT NOT NULL,
    keywords LONGTEXT NOT NULL,
    regions LONGTEXT NOT NULL,
    factions LONGTEXT NOT NULL,
    rules LONGTEXT NOT NULL,
    conflicts LONGTEXT NOT NULL,
    cover_image VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_worlds_owner (id, user_id),
    KEY idx_worlds_user_updated (user_id, updated_at),
    CONSTRAINT fk_worlds_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE characters (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    world_id INT UNSIGNED NULL,
    name VARCHAR(100) NOT NULL,
    age VARCHAR(50) NOT NULL,
    gender VARCHAR(50) NOT NULL,
    species VARCHAR(50) NOT NULL,
    occupation VARCHAR(100) NOT NULL,
    affiliation VARCHAR(100) NOT NULL,
    appearance LONGTEXT NOT NULL,
    personality LONGTEXT NOT NULL,
    values_json LONGTEXT NOT NULL,
    strengths LONGTEXT NOT NULL,
    weaknesses LONGTEXT NOT NULL,
    background LONGTEXT NOT NULL,
    goal TEXT NOT NULL,
    secret TEXT NOT NULL,
    speech_style TEXT NOT NULL,
    voice_style TEXT NOT NULL,
    habits LONGTEXT NOT NULL,
    abilities LONGTEXT NOT NULL,
    limitations LONGTEXT NOT NULL,
    profile_image VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_characters_world (id, world_id),
    KEY idx_characters_user_updated (user_id, updated_at),
    KEY idx_characters_world_owner (world_id, user_id),
    CONSTRAINT fk_characters_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_characters_world_owner FOREIGN KEY (world_id, user_id)
        REFERENCES worlds (id, user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE relationships (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    world_id INT UNSIGNED NOT NULL,
    from_character_id INT UNSIGNED NOT NULL,
    to_character_id INT UNSIGNED NOT NULL,
    relationship_type VARCHAR(50) NOT NULL,
    custom_type VARCHAR(100) NULL,
    intimacy TINYINT UNSIGNED NOT NULL,
    trust TINYINT UNSIGNED NOT NULL,
    description TEXT NOT NULL,
    first_meeting TEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_relationships_direction (world_id, from_character_id, to_character_id),
    KEY idx_relationships_from_world (from_character_id, world_id),
    KEY idx_relationships_to_world (to_character_id, world_id),
    CONSTRAINT fk_relationships_world FOREIGN KEY (world_id) REFERENCES worlds (id) ON DELETE CASCADE,
    CONSTRAINT fk_relationships_from FOREIGN KEY (from_character_id, world_id)
        REFERENCES characters (id, world_id) ON DELETE CASCADE,
    CONSTRAINT fk_relationships_to FOREIGN KEY (to_character_id, world_id)
        REFERENCES characters (id, world_id) ON DELETE CASCADE,
    CONSTRAINT chk_relationships_intimacy CHECK (intimacy BETWEEN 0 AND 100),
    CONSTRAINT chk_relationships_trust CHECK (trust BETWEEN 0 AND 100)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE chat_sessions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    character_id INT UNSIGNED NOT NULL,
    title VARCHAR(100) NOT NULL,
    mode VARCHAR(30) NOT NULL,
    user_role TEXT NOT NULL,
    situation TEXT NOT NULL,
    context_summary TEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_chat_sessions_user_updated (user_id, updated_at),
    KEY idx_chat_sessions_character (character_id),
    CONSTRAINT fk_chat_sessions_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_chat_sessions_character FOREIGN KEY (character_id)
        REFERENCES characters (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE chat_messages (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    session_id INT UNSIGNED NOT NULL,
    role VARCHAR(10) NOT NULL,
    content TEXT NOT NULL,
    action TEXT NOT NULL,
    request_id CHAR(36) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_chat_messages_request_role (session_id, request_id, role),
    KEY idx_chat_messages_session_id (session_id, id),
    CONSTRAINT fk_chat_messages_session FOREIGN KEY (session_id)
        REFERENCES chat_sessions (id) ON DELETE CASCADE,
    CONSTRAINT chk_chat_messages_role CHECK (role IN ('user', 'assistant'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE stories (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    world_id INT UNSIGNED NOT NULL,
    title VARCHAR(100) NOT NULL,
    premise TEXT NOT NULL,
    location VARCHAR(200) NOT NULL,
    mood VARCHAR(100) NOT NULL,
    user_role TEXT NOT NULL,
    character_ids LONGTEXT NOT NULL,
    context_summary TEXT NOT NULL,
    status VARCHAR(20) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_stories_user_updated (user_id, updated_at),
    KEY idx_stories_world_owner (world_id, user_id),
    CONSTRAINT fk_stories_world_owner FOREIGN KEY (world_id, user_id)
        REFERENCES worlds (id, user_id) ON DELETE CASCADE,
    CONSTRAINT chk_stories_status CHECK (status IN ('active', 'completed'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE story_turns (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    story_id INT UNSIGNED NOT NULL,
    turn_no INT UNSIGNED NOT NULL,
    request_id CHAR(36) NOT NULL,
    user_action TEXT NOT NULL,
    scene_title VARCHAR(200) NOT NULL,
    narration LONGTEXT NOT NULL,
    dialogues LONGTEXT NOT NULL,
    choices LONGTEXT NOT NULL,
    relationship_changes LONGTEXT NOT NULL,
    relationship_base LONGTEXT NOT NULL,
    timeline_event LONGTEXT NOT NULL,
    canon_applied_at DATETIME NULL,
    canon_selection LONGTEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_story_turns_number (story_id, turn_no),
    UNIQUE KEY uq_story_turns_request (story_id, request_id),
    CONSTRAINT fk_story_turns_story FOREIGN KEY (story_id) REFERENCES stories (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE timeline_events (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    world_id INT UNSIGNED NOT NULL,
    source_turn_id INT UNSIGNED NULL,
    title VARCHAR(200) NOT NULL,
    date_label VARCHAR(100) NOT NULL,
    sort_order BIGINT NOT NULL,
    event_type VARCHAR(30) NOT NULL,
    description TEXT NOT NULL,
    character_ids LONGTEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_timeline_events_source (source_turn_id),
    KEY idx_timeline_events_order (world_id, sort_order, id),
    CONSTRAINT fk_timeline_events_world FOREIGN KEY (world_id)
        REFERENCES worlds (id) ON DELETE CASCADE,
    CONSTRAINT fk_timeline_events_source FOREIGN KEY (source_turn_id)
        REFERENCES story_turns (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE favorites (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    world_id INT UNSIGNED NULL,
    character_id INT UNSIGNED NULL,
    story_id INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_favorites_world (user_id, world_id),
    UNIQUE KEY uq_favorites_character (user_id, character_id),
    UNIQUE KEY uq_favorites_story (user_id, story_id),
    CONSTRAINT fk_favorites_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_favorites_world FOREIGN KEY (world_id) REFERENCES worlds (id) ON DELETE CASCADE,
    CONSTRAINT fk_favorites_character FOREIGN KEY (character_id)
        REFERENCES characters (id) ON DELETE CASCADE,
    CONSTRAINT fk_favorites_story FOREIGN KEY (story_id) REFERENCES stories (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE ai_usage_logs (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    request_id CHAR(36) NOT NULL,
    feature VARCHAR(30) NOT NULL,
    status VARCHAR(20) NOT NULL,
    model VARCHAR(100) NOT NULL,
    error_code VARCHAR(50) NULL,
    input_tokens INT UNSIGNED NULL,
    output_tokens INT UNSIGNED NULL,
    duration_ms INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_ai_usage_logs_request (user_id, request_id),
    KEY idx_ai_usage_logs_user_created_status (user_id, created_at, status),
    CONSTRAINT fk_ai_usage_logs_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT chk_ai_usage_logs_status CHECK (status IN ('pending', 'success', 'error'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
