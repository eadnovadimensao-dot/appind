-- Igreja Manager · Schema MySQL
-- Rodar no cPanel → phpMyAdmin → selecione o banco → aba SQL

SET NAMES utf8mb4;

-- Igrejas (preparado para SaaS futuro)
CREATE TABLE churches (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  name       VARCHAR(150) NOT NULL,
  slug       VARCHAR(80)  UNIQUE NOT NULL,
  active     TINYINT(1) DEFAULT 1,
  created_at DATETIME DEFAULT NOW()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO churches (name, slug) VALUES ('Igreja Nova Dimensão', 'igrejanovadimensao');

-- Membros
CREATE TABLE members (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  church_id       INT NOT NULL,
  name            VARCHAR(150) NOT NULL,
  phone           VARCHAR(20),
  email           VARCHAR(150),
  cpf             VARCHAR(14),
  birth_date      DATE,
  gender          VARCHAR(10),
  marital_status  VARCHAR(20),
  address         TEXT,
  neighborhood    VARCHAR(100),
  city            VARCHAR(100),
  zip_code        VARCHAR(9),
  status          VARCHAR(20) DEFAULT 'active',
  baptism_date    DATE,
  conversion_date DATE,
  join_date       DATE,
  origin_church   VARCHAR(150),
  cell_id         INT,
  notes           TEXT,
  photo_url       VARCHAR(255),
  created_at      DATETIME DEFAULT NOW(),
  updated_at      DATETIME DEFAULT NOW() ON UPDATE NOW(),
  FOREIGN KEY (church_id) REFERENCES churches(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Células
CREATE TABLE cells (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  church_id   INT NOT NULL,
  name        VARCHAR(100) NOT NULL,
  leader_id   INT,
  day_of_week VARCHAR(20),
  time_start  TIME,
  address     TEXT,
  active      TINYINT(1) DEFAULT 1,
  created_at  DATETIME DEFAULT NOW(),
  FOREIGN KEY (church_id) REFERENCES churches(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Ministérios
CREATE TABLE ministries (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  church_id  INT NOT NULL,
  name       VARCHAR(100) NOT NULL,
  leader_id  INT,
  active     TINYINT(1) DEFAULT 1,
  created_at DATETIME DEFAULT NOW(),
  FOREIGN KEY (church_id) REFERENCES churches(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Membros x Ministérios
CREATE TABLE member_ministries (
  member_id   INT NOT NULL,
  ministry_id INT NOT NULL,
  joined_at   DATE,
  PRIMARY KEY (member_id, ministry_id),
  FOREIGN KEY (member_id)   REFERENCES members(id)    ON DELETE CASCADE,
  FOREIGN KEY (ministry_id) REFERENCES ministries(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Financeiro
CREATE TABLE finance_entries (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  church_id   INT NOT NULL,
  type        VARCHAR(10) NOT NULL,
  category    VARCHAR(60),
  description TEXT,
  amount      DECIMAL(12,2) NOT NULL,
  entry_date  DATE NOT NULL DEFAULT (CURRENT_DATE),
  member_id   INT,
  created_at  DATETIME DEFAULT NOW(),
  FOREIGN KEY (church_id) REFERENCES churches(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Eventos
CREATE TABLE events (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  church_id   INT NOT NULL,
  title       VARCHAR(150) NOT NULL,
  description TEXT,
  event_date  DATE NOT NULL,
  time_start  TIME,
  time_end    TIME,
  location    VARCHAR(200),
  created_at  DATETIME DEFAULT NOW(),
  FOREIGN KEY (church_id) REFERENCES churches(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Escala de Louvor
CREATE TABLE worship_scales (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  church_id  INT NOT NULL,
  event_id   INT,
  scale_date DATE NOT NULL,
  notes      TEXT,
  created_at DATETIME DEFAULT NOW(),
  FOREIGN KEY (church_id) REFERENCES churches(id),
  FOREIGN KEY (event_id)  REFERENCES events(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE worship_scale_members (
  scale_id  INT NOT NULL,
  member_id INT NOT NULL,
  role      VARCHAR(60),
  PRIMARY KEY (scale_id, member_id),
  FOREIGN KEY (scale_id)  REFERENCES worship_scales(id) ON DELETE CASCADE,
  FOREIGN KEY (member_id) REFERENCES members(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Índices
CREATE INDEX idx_members_church  ON members(church_id);
CREATE INDEX idx_members_status  ON members(status);
CREATE INDEX idx_members_cell    ON members(cell_id);
CREATE INDEX idx_finance_church  ON finance_entries(church_id);
CREATE INDEX idx_finance_date    ON finance_entries(entry_date);
CREATE INDEX idx_events_church   ON events(church_id);
CREATE INDEX idx_events_date     ON events(event_date);

-- Núcleos familiares
CREATE TABLE families (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  church_id  INT NOT NULL,
  name       VARCHAR(150) NOT NULL,
  created_at DATETIME DEFAULT NOW(),
  FOREIGN KEY (church_id) REFERENCES churches(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE members ADD COLUMN family_id INT NULL AFTER church_id;
ALTER TABLE members ADD FOREIGN KEY (family_id) REFERENCES families(id);
ALTER TABLE members ADD COLUMN family_role VARCHAR(30) NULL AFTER family_id;
-- family_role: head | spouse | child | other
