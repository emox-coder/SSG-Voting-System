<?php
declare(strict_types=1);

function ssg_db(): mysqli
{
    static $connection = null;

    if ($connection instanceof mysqli) {
        return $connection;
    }

    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

    // Database configuration
    $host = getenv('DB_HOST') ?: '127.0.0.1';
    $port = (int) (getenv('DB_PORT') ?: '3306');
    $name = getenv('DB_NAME') ?: 'ssg_voting';
    $user = getenv('DB_USER') ?: 'root';
    $password = getenv('DB_PASSWORD') ?: '';

    // Validate database name
    if (!preg_match('/\A[a-zA-Z0-9_]+\z/', $name)) {
        throw new RuntimeException(
            'DB_NAME may contain only letters, numbers, and underscores.'
        );
    }

    // Connect to MySQL database
    $connection = new mysqli(
        $host,
        $user,
        $password,
        $name,
        $port
    );

    // Set character encoding
    $connection->set_charset('utf8mb4');

    // Database schema
    $schema = [
        "CREATE TABLE IF NOT EXISTS users (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(32) NOT NULL UNIQUE,
            display_name VARCHAR(100) NOT NULL,
            password_hash VARCHAR(255) NOT NULL,
            role ENUM('admin', 'student') NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB",

        "CREATE TABLE IF NOT EXISTS positions (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(80) NOT NULL UNIQUE,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB",

        "CREATE TABLE IF NOT EXISTS candidates (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            position_id INT UNSIGNED NOT NULL,
            name VARCHAR(100) NOT NULL,
            statement TEXT NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_candidates_position
                FOREIGN KEY (position_id) REFERENCES positions(id)
        ) ENGINE=InnoDB",

        "CREATE TABLE IF NOT EXISTS voter_participation (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            voter_id INT UNSIGNED NOT NULL UNIQUE,
            submitted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_participation_voter
                FOREIGN KEY (voter_id) REFERENCES users(id)
        ) ENGINE=InnoDB",

        "CREATE TABLE IF NOT EXISTS ballots (
            position_id INT UNSIGNED NOT NULL,
            candidate_id INT UNSIGNED NOT NULL,
            KEY ballots_position (position_id),
            KEY ballots_candidate (candidate_id),
            CONSTRAINT fk_ballots_position
                FOREIGN KEY (position_id) REFERENCES positions(id),
            CONSTRAINT fk_ballots_candidate
                FOREIGN KEY (candidate_id) REFERENCES candidates(id)
        ) ENGINE=InnoDB",

        "CREATE TABLE IF NOT EXISTS election_settings (
            id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
            is_open TINYINT(1) NOT NULL DEFAULT 0,
            results_published TINYINT(1) NOT NULL DEFAULT 0,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB"
    ];

    // Create tables if they do not exist
    foreach ($schema as $statement) {
        $connection->query($statement);
    }

    // Initialize election settings
    $connection->query(
        'INSERT IGNORE INTO election_settings
        (id, is_open, results_published)
        VALUES (1, 0, 0)'
    );

    return $connection;
}
