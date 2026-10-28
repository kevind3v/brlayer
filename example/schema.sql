-- Banco dos exemplos: mysql -uroot -p brlayer_example < example/schema.sql
DROP TABLE IF EXISTS adresses, companies, users;

CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    first_name VARCHAR(100) NOT NULL,
    last_name VARCHAR(100) NOT NULL,
    genre CHAR(1) NULL,
    created_at DATETIME NULL,
    updated_at DATETIME NULL
) CHARACTER SET utf8mb4;

CREATE TABLE adresses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    address VARCHAR(255) NULL,
    created_at DATETIME NULL,
    updated_at DATETIME NULL
) CHARACTER SET utf8mb4;

CREATE TABLE companies (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    name VARCHAR(255) NOT NULL,
    created_at DATETIME NULL,
    updated_at DATETIME NULL
) CHARACTER SET utf8mb4;

INSERT INTO users (first_name, last_name, genre, created_at, updated_at) VALUES
    ('Robson', 'V. Leite', 'm', NOW(), NOW()),
    ('Ana', 'Silva', 'f', NOW(), NOW()),
    ('Bruno', 'Souza', 'm', NOW(), NOW()),
    ('Carla', 'Lima', 'f', NOW(), NOW()),
    ('Daniel', 'Rocha', 'm', NOW(), NOW()),
    ('Eva', 'Reis', 'f', NOW(), NOW());

INSERT INTO adresses (user_id, address, created_at, updated_at) VALUES
    (1, 'Rua do Robson, Nº 10', NOW(), NOW());

INSERT INTO companies (user_id, name, created_at, updated_at) VALUES
    (1, 'UpInside', NOW(), NOW());
