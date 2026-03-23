CREATE TABLE IF NOT EXISTS downloads_galeria (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre_archivo VARCHAR(255) NOT NULL,
    mime_type VARCHAR(100) NOT NULL,
    contenido LONGBLOB NOT NULL,
    sha1 CHAR(40) NOT NULL,
    size_bytes INT NOT NULL,
    comentario TEXT NULL,
    tipo_archivo VARCHAR(50) NULL DEFAULT 'Imagen',
    categoria VARCHAR(30) NULL,
    visible VARCHAR(3) DEFAULT 'SI',
    engine_ia VARCHAR(30) NULL,
    fecha_archivo DATETIME NOT NULL,
    fecha_registro DATETIME NOT NULL,
    INDEX (sha1),
    INDEX (categoria),
    INDEX (visible),
    INDEX (fecha_registro)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS downloads_documentos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre_archivo VARCHAR(255) NOT NULL,
    mime_type VARCHAR(100) NOT NULL,
    contenido LONGBLOB NOT NULL,
    sha1 CHAR(40) NOT NULL,
    size_bytes INT NOT NULL,
    comentario TEXT NULL,
    tipo_archivo VARCHAR(50) NULL DEFAULT 'Documento',
    categoria VARCHAR(30) NULL,
    visible VARCHAR(3) DEFAULT 'SI',
    engine_ia VARCHAR(30) NULL,
    fecha_archivo DATETIME NOT NULL,
    fecha_registro DATETIME NOT NULL,
    INDEX (sha1),
    INDEX (categoria),
    INDEX (visible)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
