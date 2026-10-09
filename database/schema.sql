CREATE DATABASE IF NOT EXISTS inventario_api
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE inventario_api;

CREATE TABLE IF NOT EXISTS usuarios (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nombre        VARCHAR(80)  NOT NULL,
  email         VARCHAR(120) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  creado_en     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Solo se guarda el hash (SHA-256) del token: si la base de datos se filtra,
-- los tokens reales no quedan expuestos.
CREATE TABLE IF NOT EXISTS tokens (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  usuario_id  INT UNSIGNED NOT NULL,
  token_hash  CHAR(64)     NOT NULL UNIQUE,
  expira_en   DATETIME     NOT NULL,
  creado_en   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_tokens_usuario
    FOREIGN KEY (usuario_id) REFERENCES usuarios (id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS productos (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  usuario_id      INT UNSIGNED  NOT NULL,
  nombre          VARCHAR(120)  NOT NULL,
  sku             VARCHAR(40)   NOT NULL,
  categoria       VARCHAR(60)   NULL,
  precio          DECIMAL(12,2) NOT NULL DEFAULT 0,
  stock           INT UNSIGNED  NOT NULL DEFAULT 0,
  creado_en       TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado_en  TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_producto_sku (usuario_id, sku),
  INDEX idx_producto_categoria (usuario_id, categoria),
  CONSTRAINT fk_productos_usuario
    FOREIGN KEY (usuario_id) REFERENCES usuarios (id) ON DELETE CASCADE
) ENGINE=InnoDB;
