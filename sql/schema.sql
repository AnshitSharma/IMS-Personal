CREATE TABLE CPUInventory (
    ID INT PRIMARY KEY AUTO_INCREMENT,
    UUID VARCHAR(36) UNIQUE NOT NULL COMMENT 'Links to detailed specs in JSON',
    SerialNumber VARCHAR(50) UNIQUE COMMENT 'Manufacturer serial number',
    Status ENUM('In Use', 'In Stock', 'Maintenance', 'Decommissioned', 'Failed') NOT NULL,
    ServerUUID VARCHAR(36) NULL COMMENT 'UUID of server where CPU is installed, if any',
    Location VARCHAR(100) COMMENT 'Physical location like datacenter, warehouse',
    RackPosition VARCHAR(20) COMMENT 'Specific rack/shelf position',
    
    PurchaseDate DATE,
    InstallationDate DATE COMMENT 'When installed in current server',
    WarrantyEndDate DATE,
    
    Flag VARCHAR(50) COMMENT 'Quick status flag or category',
    Notes TEXT COMMENT 'Any additional info or history',
    
    CreatedAt TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UpdatedAt TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP

);

CREATE TABLE RAMInventory (
    ID INT PRIMARY KEY AUTO_INCREMENT,
    UUID VARCHAR(36) UNIQUE NOT NULL COMMENT 'Links to detailed specs in JSON',
    SerialNumber VARCHAR(50) UNIQUE COMMENT 'Manufacturer serial number',
    Status ENUM('In Use', 'In Stock', 'Maintenance', 'Decommissioned', 'Failed') NOT NULL,
    ServerUUID VARCHAR(36) NULL COMMENT 'UUID of server where RAM is installed, if any',
    Location VARCHAR(100) COMMENT 'Physical location like datacenter, warehouse',
    RackPosition VARCHAR(20) COMMENT 'Specific rack/shelf position',
    
    PurchaseDate DATE,
    InstallationDate DATE COMMENT 'When installed in current server',
    WarrantyEndDate DATE,
    
    Flag VARCHAR(50) COMMENT 'Quick status flag or category',
    Notes TEXT COMMENT 'Any additional info or history',
    
    CreatedAt TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UpdatedAt TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE MotherboardInventory (
    ID INT PRIMARY KEY AUTO_INCREMENT,
    UUID VARCHAR(36) UNIQUE NOT NULL COMMENT 'Links to detailed specs in JSON',
    SerialNumber VARCHAR(50) UNIQUE COMMENT 'Manufacturer serial number',
    Status ENUM('In Use', 'In Stock', 'Maintenance', 'Decommissioned', 'Failed') NOT NULL,
    ServerUUID VARCHAR(36) NULL COMMENT 'UUID of server where motherboard is installed, if any',
    Location VARCHAR(100) COMMENT 'Physical location like datacenter, warehouse',
    RackPosition VARCHAR(20) COMMENT 'Specific rack/shelf position',
    
    PurchaseDate DATE,
    InstallationDate DATE COMMENT 'When installed in current server',
    WarrantyEndDate DATE,
    
    Flag VARCHAR(50) COMMENT 'Quick status flag or category',
    Notes TEXT COMMENT 'Any additional info or history',
    
    CreatedAt TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UpdatedAt TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE StorageInventory (
    ID INT PRIMARY KEY AUTO_INCREMENT,
    UUID VARCHAR(36) UNIQUE NOT NULL COMMENT 'Links to detailed specs in JSON', 
    SerialNumber VARCHAR(50) UNIQUE COMMENT 'Manufacturer serial number',
    Status ENUM('In Use', 'In Stock', 'Maintenance', 'Decommissioned', 'Failed') NOT NULL,
    ServerUUID VARCHAR(36) NULL COMMENT 'UUID of server where storage is installed, if any',
    Location VARCHAR(100) COMMENT 'Physical location like datacenter, warehouse',
    RackPosition VARCHAR(20) COMMENT 'Specific rack/shelf position',
    
    PurchaseDate DATE,
    InstallationDate DATE COMMENT 'When installed in current server',
    WarrantyEndDate DATE,
    
    Flag VARCHAR(50) COMMENT 'Quick status flag or category',
    Notes TEXT COMMENT 'Any additional info or history',
    
    CreatedAt TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UpdatedAt TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE CaddyInventory (
    ID INT PRIMARY KEY AUTO_INCREMENT,
    UUID VARCHAR(36) UNIQUE NOT NULL COMMENT 'Links to detailed specs in JSON',
    SerialNumber VARCHAR(50) UNIQUE COMMENT 'Manufacturer serial number',
    Status ENUM('In Use', 'In Stock', 'Maintenance', 'Decommissioned', 'Failed') NOT NULL,
    ServerUUID VARCHAR(36) NULL COMMENT 'UUID of server where caddy is installed, if any',
    Location VARCHAR(100) COMMENT 'Physical location like datacenter, warehouse',
    RackPosition VARCHAR(20) COMMENT 'Specific rack/shelf position',
    
    PurchaseDate DATE,
    InstallationDate DATE COMMENT 'When installed in current server',
    WarrantyEndDate DATE,
    
    Flag VARCHAR(50) COMMENT 'Quick status flag or category',
    Notes TEXT COMMENT 'Any additional info or history',
    
    CreatedAt TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UpdatedAt TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

