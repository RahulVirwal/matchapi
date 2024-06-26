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
    FOREIGN KEY (match_name) REFERENCES matches(name) ON DELETE CASCADE ON UPDATE CASCADE
);

-- Create players table
CREATE TABLE IF NOT EXISTS players (
    id INT AUTO_INCREMENT PRIMARY KEY,
    team_name VARCHAR(255) NOT NULL,
    match_name VARCHAR(255) NOT NULL,
    player_name VARCHAR(255) NOT NULL UNIQUE,
    player_shortname VARCHAR(255) NOT NULL,
    player_image VARCHAR(255) NOT NULL,
    FOREIGN KEY (team_name) REFERENCES manageteam(team_name) ON DELETE CASCADE ON UPDATE CASCADE,
    FOREIGN KEY (match_name) REFERENCES manageteam(match_name) ON DELETE CASCADE ON UPDATE CASCADE
);

-- Insert sample data into matches table
INSERT INTO matches (name, shortname, image) VALUES 
('Match One', 'M1', 'image1.png'),
('Match Two', 'M2', 'image2.png');

-- Insert sample data into manageteam table
INSERT INTO manageteam (team_name, match_name, shortname, image) VALUES 
('Team A', 'Match One', 'TA', 'teamA.png'),
('Team B', 'Match One', 'TB', 'teamB.png'),
('Team C', 'Match Two', 'TC', 'teamC.png');

-- Insert sample data into players table
INSERT INTO players (team_name, match_name, player_name, player_shortname, player_image) VALUES 
('Team A', 'Match One', 'Player 1', 'P1', 'player1.png'),
('Team A', 'Match One', 'Player 2', 'P2', 'player2.png'),
('Team B', 'Match One', 'Player 3', 'P3', 'player3.png'),
('Team C', 'Match Two', 'Player 4', 'P4', 'player4.png');
