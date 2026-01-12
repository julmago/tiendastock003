CREATE TABLE IF NOT EXISTS product_images (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  product_id BIGINT UNSIGNED NOT NULL,
  filename_base VARCHAR(190) NOT NULL,
  position INT NOT NULL,
  is_cover TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_pi_product (product_id),
  KEY idx_pi_product_position (product_id, position),
  CONSTRAINT fk_pi_product FOREIGN KEY (product_id) REFERENCES provider_products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
