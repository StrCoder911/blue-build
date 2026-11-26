-- Atoll Rising server directory (MySQL on the Hostinger web host)
CREATE TABLE IF NOT EXISTS servers (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(32) NOT NULL UNIQUE,         -- the subdomain: official01, jonnys-arena, reef-4821
  secret_hash CHAR(64) NOT NULL,            -- sha256 of the server's secret key
  ip VARCHAR(45) NOT NULL,
  port INT NOT NULL DEFAULT 25564,
  title VARCHAR(40) NOT NULL DEFAULT '',
  official TINYINT NOT NULL DEFAULT 0,
  players INT NOT NULL DEFAULT 0,
  games INT NOT NULL DEFAULT 0,
  version VARCHAR(20) NOT NULL DEFAULT '',
  reachable TINYINT NOT NULL DEFAULT 0,
  checked_at INT NOT NULL DEFAULT 0,
  last_seen INT NOT NULL,
  created INT NOT NULL,
  blocked TINYINT NOT NULL DEFAULT 0
);
CREATE TABLE IF NOT EXISTS hits (
  k VARCHAR(80) NOT NULL,
  t INT NOT NULL,
  KEY (k, t)
);
