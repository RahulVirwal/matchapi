-- Create the database if it does not exist
CREATE DATABASE IF NOT EXISTS match_db;

-- Use the created database
USE match_db;

-- Drop tables if they exist (for demonstration purposes)
DROP TABLE IF EXISTS players;
DROP TABLE IF EXISTS manageteam;
DROP TABLE IF EXISTS matches;

-- Create matches table
CREATE TABLE IF NOT EXISTS matches ( 
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL UNIQUE,
    shortname VARCHAR(255) NOT NULL,
    image VARCHAR(255) NOT NULL
);

-- Create manageteam table
CREATE TABLE IF NOT EXISTS manageteam (
    id INT AUTO_INCREMENT PRIMARY KEY,
    team_name VARCHAR(255) NOT NULL UNIQUE,
    match_name VARCHAR(255) NOT NULL,
    shortname VARCHAR(255) NOT NULL,
    image VARCHAR(255) NOT NULL,
    FOREIGN KEY (match_name) REFERENCES matches(name) ON DELETE CASCADE
);

-- Create players table
CREATE TABLE IF NOT EXISTS players (
    id INT AUTO_INCREMENT PRIMARY KEY,
    team_name VARCHAR(255) NOT NULL,
    player_name VARCHAR(255) NOT NULL UNIQUE,
    player_shortname VARCHAR(255) NOT NULL,
    player_image VARCHAR(255) NOT NULL,
    FOREIGN KEY (team_name) REFERENCES manageteam(team_name) ON DELETE CASCADE
);
