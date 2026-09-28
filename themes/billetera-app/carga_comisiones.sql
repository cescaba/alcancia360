-- ============================================================================
-- MIGRACIÓN DE COMISIONES - MI ALCANCIA 360
-- ============================================================================
-- Fecha: 2026-08-09
-- Base de datos: WordPress Billetera
--
-- INSTRUCCIONES:
-- 1. Copiar todo este contenido
-- 2. Ir a phpMyAdmin o tu gestor de BD
-- 3. Seleccionar la BD de billetera
-- 4. Ir a SQL y pegar el contenido
-- 5. Ejecutar
--
-- ROLES MAPEADOS:
-- - Asesor (Excel) → asesor (BD)
-- - Jefe (Excel) → jefe_venta (BD)
-- - Dealer (Excel) → IGNORADO
--
-- DISTRIBUIDORES:
-- - TODOS → NULL (comisión genérica)sdfdsf
-- - MANASA → 12
-- - AUTOMOTRIZ INCAMOTORS S.A.C → 13
-- - MAQUINARIAS → 14
-- - SAN ANTONIO TRADE → 15
-- - SPORTWAGEN → 16
--
-- ============================================================================

-- PASO 1: INSERTAR MARCAS (agrupador)
-- ============================================================================
INSERT IGNORE INTO wp_billetera_marcas (nombre, slug, activo) VALUES
('Hyundai', 'hyundai', 1),
('JMC', 'jmc', 1),
('Geely', 'geely', 1);

-- PASO 1.1: INSERTAR LÍNEAS (hyu/hcv/gee/jmc) ligadas a su marca
-- ============================================================================
INSERT IGNORE INTO wp_billetera_lineas (codigo, nombre, marca_id, orden_display, activo) VALUES
('hyu', 'Hyundai Autos',    (SELECT id FROM wp_billetera_marcas WHERE slug = 'hyundai'), 1, 1),
('hcv', 'Hyundai Camiones', (SELECT id FROM wp_billetera_marcas WHERE slug = 'hyundai'), 2, 1),
('gee', 'Geely',            (SELECT id FROM wp_billetera_marcas WHERE slug = 'geely'),   3, 1),
('jmc', 'JMC',              (SELECT id FROM wp_billetera_marcas WHERE slug = 'jmc'),     4, 1);

-- PASO 2: OBTENER IDs DE MARCAS (para referencias)
-- SELECT @hyundai_id := id FROM wp_billetera_marcas WHERE slug = 'hyundai';
-- SELECT @jmc_id := id FROM wp_billetera_marcas WHERE slug = 'jmc';
-- SELECT @geely_id := id FROM wp_billetera_marcas WHERE slug = 'geely';

-- PASO 3: INSERTAR CATEGORÍAS
-- ============================================================================
INSERT IGNORE INTO wp_billetera_categorias (linea_codigo, nombre, slug, orden_display, activo) VALUES
('hyu','Kit360','Kit360',1,1),
('hyu','Kit Seguridad','Kit Seguridad',2,1),
('hyu','Prepagados','Prepagados',3,1),
('hyu','Baterías','Baterías',4,1),
('hyu','i3000+','i3000+',5,1),
('hyu','Aditivos Liquimoly','Aditivos Liquimoly',6,1),
('hyu','Glasscoat','Glasscoat',7,1),
('hyu','Airlife','Airlife',8,1),
('hyu','ECOEVOL','ECOEVOL',9,1),
('jmc','Kit360','Kit360',1,1),
('jmc','Llantas Cosmo','Llantas Cosmo',2,1),
('jmc','Kit Seguridad','Kit Seguridad',3,1),
('jmc','Prepagados','Prepagados',4,1),
('jmc','Baterías','Baterías',5,1),
('jmc','i3000+','i3000+',6,1),
('jmc','Aditivos Liquimoly','Aditivos Liquimoly',7,1),
('jmc','Llantas','Llantas',8,1),
('gee','Kit360','Kit360',1,1),
('gee','Prepagados','Prepagados',2,1),
('gee','Baterías','Baterías',3,1),
('gee','i3000+','i3000+',4,1),
('gee','Aditivos Liquimoly','Aditivos Liquimoly',5,1),
('gee','Glasscoat','Glasscoat',6,1);




-- PASO 4: INSERTAR SUBCATEGORÍAS (Kit360)
-- ============================================================================
INSERT IGNORE INTO wp_billetera_subcategorias (categoria_id, nombre, por_unidad, orden_display, activo) VALUES
(1,'Gold',0,1,1),
(1,'Bronze',0,2,1),
(10,'Gold',0,3,1),
(10,'Bronze',0,4,1),
(18,'Gold',0,5,1),
(18,'Bronze',0,6,1),
(11,'Mudkicker Juego',0,7,1),
(11,'Mudkicker Unidad',1,8,1),
(11,'GripitXT',0,9,1),
(11,'GripitXT Unidad',1,10,1),
(12,'Pick Ups',0,11,1),
(12,'Camiones',0,12,1),
(2,'Camiones',0,13,1),
(3,'2-3 mpps',0,14,1),
(3,'4-5 mpps',0,15,1),
(3,'6-7 mpps',0,16,1),
(3,'8-9 mpps',0,17,1),
(3,'10 mpps / 50,000 km',0,18,1),
(13,'2-3 mpps',0,19,1),
(13,'4-5 mpps',0,20,1),
(13,'6-7 mpps',0,21,1),
(13,'8-9 mpps',0,22,1),
(13,'10 mpps / 50,000 km',0,23,1),
(19,'2 mpps',0,24,1),
(19,'3 mpps',0,25,1),
(19,'4 mpps',0,26,1),
(19,'5+ mpps',0,27,1),
(4,'44B19FL - Batería 187',0,28,1),
(4,'55054 - Batería 207',0,29,1),
(4,'80D23L - Batería 231',0,30,1),
(4,'56077 - Batería 242',0,31,1),
(4,'24R-670 - Batería 258',0,32,1),
(4,'56828 - Batería 277',0,33,1),
(4,'105D31R - Batería 303',0,34,1),
(4,'105D31L - Batería 303',0,35,1),
(4,'94R-800 - Batería 315',0,36,1),
(4,'94L-800 - Batería 315',0,37,1),
(4,'115D33R - Batería 326',0,38,1),
(20,'56077G - Batería 242',0,39,1),
(20,'56828G - Batería 277',0,40,1),
(20,'JMC105D31L - Batería 303',0,41,1),
(14,'JCM105D31R - Batería 303',0,42,1),
(5,'i3000+ GASOL. HYUNDAI',0,43,1),
(5,'i3000+ DIESEL 500ml HYUNDAI',0,44,1),
(5,'i3000+ MAQUILADO (SIN LOGO HYUNDAI)',0,45,1),
(5,'i3000+ GASOL. -VOLVO (SIN LOGO HYUNDAI)',0,46,1),
(5,'i3000+ GASOL. JLRO (SIN LOGO HYUNDAI)',0,47,1),
(21,'I3000+ GASOL. (SIN LOGO HYUNDAI)',0,48,1),
(15,'I3000+  DIESEL (SIN LOGO HYUNDAI)',0,49,1),
(6,'Pack Inyección + Obturador (Vehículos Diesel)',0,50,1),
(6,'PACK MANTEMIENTO DE FRENOS (3 Productos)',0,51,1),
(6,'PACK DPF Preventivo',0,52,1),
(6,'Pack Inyección + Obturador (Vehículos Diesel)',0,53,1),
(6,'PACK DE INYECCIÓN GASOLINA (2 Productos)',0,54,1),
(6,'PACK DE INYECCIÓN DIESEL (2 Productos)',0,55,1),
(6,'PACK DE LIMPIEZA DE INYECCIÓN Y OBTURADOR (3 Productos)',0,56,1),
(6,'PACK MANTENIMIENTO DE MOTOR (2 Productos, Gasolina & Diesel)',0,57,1),
(6,'PACK MANTENIMIENTO RADIADOR (2 Productos)',0,58,1),
(6,'PACK MANTEMIENTO DE FRENOS (3 Productos)',0,59,1),
(6,'PACK MANTENIMIENTO DIRECCIÓN HIDRÁULICA (2 Productos)',0,60,1),
(6,'Pack Inyección Diesel',0,61,1),
(6,'Pack Inyección Gasolina',0,62,1),
(6,'KLIMA REFRESH: LIMPIEZA DE A/C',0,63,1),
(16,'PACK DE INYECCIÓN DIESEL (2 Productos)',0,64,1),
(22,'PACK DE INYECCIÓN GASOLINA (2 Productos)',0,65,1),
(22,'PACK DE LIMPIEZA DE INYECCIÓN Y OBTURADOR (3 Productos)',0,66,1),
(22,'PACK MANTENIMIENTO DE MOTOR (2 Productos, Gasolina & Diesel)',0,67,1),
(22,'PACK MANTENIMIENTO RADIADOR (2 Productos)',0,68,1),
(22,'PACK MANTEMIENTO DE FRENOS (3 Productos)',0,69,1),
(22,'PACK MANTENIMIENTO DIRECCIÓN HIDRÁULICA (2 Productos)',0,70,1),
(7,'HYUNDAI BRONZE PACK - GLASSCOAT',0,71,1),
(7,'HYUNDAI GOLD PACK- GLASSCOAT',0,72,1),
(7,'GOLD PACK- GLASSCOAT LRO (PARA REPOSICION)',0,73,1),
(7,'GOLD PACK- GLASSCOAT VOLVO (PARA REPOSICION)',0,74,1),
(23,'GEELY BRONZE PACK - GLASSCOAT',0,75,1),
(23,'GEELY  GOLD PACK- GLASSCOAT',0,76,1),
(8,'Venta Servicio Airlife',0,77,1),
(9,'PURIFICADOR DE DIESEL ECOEVOL F+',0,78,1),
(9,'CATALIZADOR DE COMBUSTIBLE ECOEVOL C',1,79,1),
(17,'GITIXROSS HT71 265/65R17 GRAND VIGUS 4X4 LUX (2026)',0,80,1),
(17,'275/70R18  GRIPIT XT',0,81,1),
(17,'265/70R17 GRIPIT XT',0,82,1),
(17,'265/70R17  MUD KICKER MT',0,83,1);



-- Hyundai Kit360
((SELECT id FROM wp_billetera_categorias WHERE slug = 'kit360' AND linea_codigo = 'hyu'), 'Gold', 0, 1, 1),
((SELECT id FROM wp_billetera_categorias WHERE slug = 'kit360' AND linea_codigo = 'hyu'), 'Bronze', 0, 2, 1),
-- JMC Kit360
((SELECT id FROM wp_billetera_categorias WHERE slug = 'kit360' AND linea_codigo = 'jmc'), 'Gold', 0, 1, 1),
((SELECT id FROM wp_billetera_categorias WHERE slug = 'kit360' AND linea_codigo = 'jmc'), 'Bronze', 0, 2, 1),
-- Geely Kit360
((SELECT id FROM wp_billetera_categorias WHERE slug = 'kit360' AND linea_codigo = 'gee'), 'Gold', 0, 1, 1),
((SELECT id FROM wp_billetera_categorias WHERE slug = 'kit360' AND linea_codigo = 'gee'), 'Bronze', 0, 2, 1);

-- PASO 5: INSERTAR SUBCATEGORÍAS (Llantas)
-- ============================================================================
INSERT IGNORE INTO wp_billetera_subcategorias (categoria_id, nombre, por_unidad, orden_display, activo) VALUES
-- JMC Llantas Cosmo
((SELECT id FROM wp_billetera_categorias WHERE slug = 'llantas-cosmo' AND linea_codigo = 'jmc'), 'Mudkicker Juego', 0, 1, 1),
((SELECT id FROM wp_billetera_categorias WHERE slug = 'llantas-cosmo' AND linea_codigo = 'jmc'), 'Mudkicker Unidad', 1, 2, 1),
((SELECT id FROM wp_billetera_categorias WHERE slug = 'llantas-cosmo' AND linea_codigo = 'jmc'), 'GripitXT', 0, 3, 1),
((SELECT id FROM wp_billetera_categorias WHERE slug = 'llantas-cosmo' AND linea_codigo = 'jmc'), 'GripitXT Unidad', 1, 4, 1),
-- JMC Llantas
((SELECT id FROM wp_billetera_categorias WHERE slug = 'llantas' AND linea_codigo = 'jmc'), 'GITIXROSS HT71 265/65R17', 0, 1, 1),
((SELECT id FROM wp_billetera_categorias WHERE slug = 'llantas' AND linea_codigo = 'jmc'), '275/70R18 GRIPIT XT', 0, 2, 1),
((SELECT id FROM wp_billetera_categorias WHERE slug = 'llantas' AND linea_codigo = 'jmc'), '265/70R17 GRIPIT XT', 0, 3, 1),
((SELECT id FROM wp_billetera_categorias WHERE slug = 'llantas' AND linea_codigo = 'jmc'), '265/70R17 MUD KICKER MT', 0, 4, 1);

-- PASO 6: INSERTAR SUBCATEGORÍAS (Kit Seguridad)
-- ============================================================================
INSERT IGNORE INTO wp_billetera_subcategorias (categoria_id, nombre, por_unidad, orden_display, activo) VALUES
-- Hyundai Kit Seguridad
((SELECT id FROM wp_billetera_categorias WHERE slug = 'kit-seguridad' AND linea_codigo = 'hyu'), 'Pick Ups', 0, 1, 1),
((SELECT id FROM wp_billetera_categorias WHERE slug = 'kit-seguridad' AND linea_codigo = 'hyu'), 'Camiones', 0, 2, 1),
-- JMC Kit Seguridad
((SELECT id FROM wp_billetera_categorias WHERE slug = 'kit-seguridad' AND linea_codigo = 'jmc'), 'Pick Ups', 0, 1, 1),
((SELECT id FROM wp_billetera_categorias WHERE slug = 'kit-seguridad' AND linea_codigo = 'jmc'), 'Camiones', 0, 2, 1);

-- PASO 7: INSERTAR SUBCATEGORÍAS (Prepagados)
-- ============================================================================
INSERT IGNORE INTO wp_billetera_subcategorias (categoria_id, nombre, por_unidad, orden_display, activo) VALUES
-- Hyundai Prepagados
((SELECT id FROM wp_billetera_categorias WHERE slug = 'prepagados' AND linea_codigo = 'hyu'), '2-3 mpps', 0, 1, 1),
((SELECT id FROM wp_billetera_categorias WHERE slug = 'prepagados' AND linea_codigo = 'hyu'), '4-5 mpps', 0, 2, 1),
((SELECT id FROM wp_billetera_categorias WHERE slug = 'prepagados' AND linea_codigo = 'hyu'), '6-7 mpps', 0, 3, 1),
((SELECT id FROM wp_billetera_categorias WHERE slug = 'prepagados' AND linea_codigo = 'hyu'), '8-9 mpps', 0, 4, 1),
((SELECT id FROM wp_billetera_categorias WHERE slug = 'prepagados' AND linea_codigo = 'hyu'), '10 mpps / 50,000 km', 0, 5, 1),
-- JMC Prepagados
((SELECT id FROM wp_billetera_categorias WHERE slug = 'prepagados' AND linea_codigo = 'jmc'), '2-3 mpps', 0, 1, 1),
((SELECT id FROM wp_billetera_categorias WHERE slug = 'prepagados' AND linea_codigo = 'jmc'), '4-5 mpps', 0, 2, 1),
((SELECT id FROM wp_billetera_categorias WHERE slug = 'prepagados' AND linea_codigo = 'jmc'), '6-7 mpps', 0, 3, 1),
((SELECT id FROM wp_billetera_categorias WHERE slug = 'prepagados' AND linea_codigo = 'jmc'), '8-9 mpps', 0, 4, 1),
((SELECT id FROM wp_billetera_categorias WHERE slug = 'prepagados' AND linea_codigo = 'jmc'), '10 mpps / 50,000 km', 0, 5, 1),
-- Geely Prepagados
((SELECT id FROM wp_billetera_categorias WHERE slug = 'prepagados' AND linea_codigo = 'gee'), '2 mpps', 0, 1, 1),
((SELECT id FROM wp_billetera_categorias WHERE slug = 'prepagados' AND linea_codigo = 'gee'), '3 mpps', 0, 2, 1),
((SELECT id FROM wp_billetera_categorias WHERE slug = 'prepagados' AND linea_codigo = 'gee'), '4 mpps', 0, 3, 1),
((SELECT id FROM wp_billetera_categorias WHERE slug = 'prepagados' AND linea_codigo = 'gee'), '5+ mpps', 0, 4, 1);

-- PASO 8: INSERTAR SUBCATEGORÍAS (Baterías)
-- ============================================================================
INSERT IGNORE INTO wp_billetera_subcategorias (categoria_id, nombre, por_unidad, orden_display, activo) VALUES
-- Hyundai Baterías
((SELECT id FROM wp_billetera_categorias WHERE slug = 'baterias' AND linea_codigo = 'hyu'), '44B19FL - Batería 187', 0, 1, 1),
((SELECT id FROM wp_billetera_categorias WHERE slug = 'baterias' AND linea_codigo = 'hyu'), '55054 - Batería 207', 0, 2, 1),
((SELECT id FROM wp_billetera_categorias WHERE slug = 'baterias' AND linea_codigo = 'hyu'), '80D23L - Batería 231', 0, 3, 1),
((SELECT id FROM wp_billetera_categorias WHERE slug = 'baterias' AND linea_codigo = 'hyu'), '56077 - Batería 242', 0, 4, 1),
((SELECT id FROM wp_billetera_categorias WHERE slug = 'baterias' AND linea_codigo = 'hyu'), '24R-670 - Batería 258', 0, 5, 1),
((SELECT id FROM wp_billetera_categorias WHERE slug = 'baterias' AND linea_codigo = 'hyu'), '56828 - Batería 277', 0, 6, 1),
((SELECT id FROM wp_billetera_categorias WHERE slug = 'baterias' AND linea_codigo = 'hyu'), '105D31R - Batería 303', 0, 7, 1),
((SELECT id FROM wp_billetera_categorias WHERE slug = 'baterias' AND linea_codigo = 'hyu'), '105D31L - Batería 303', 0, 8, 1),
((SELECT id FROM wp_billetera_categorias WHERE slug = 'baterias' AND linea_codigo = 'hyu'), '94R-800 - Batería 315', 0, 9, 1),
((SELECT id FROM wp_billetera_categorias WHERE slug = 'baterias' AND linea_codigo = 'hyu'), '94L-800 - Batería 315', 0, 10, 1),
((SELECT id FROM wp_billetera_categorias WHERE slug = 'baterias' AND linea_codigo = 'hyu'), '115D33R - Batería 326', 0, 11, 1),
-- Geely Baterías
((SELECT id FROM wp_billetera_categorias WHERE slug = 'baterias' AND linea_codigo = 'gee'), '56077G - Batería 242', 0, 1, 1),
((SELECT id FROM wp_billetera_categorias WHERE slug = 'baterias' AND linea_codigo = 'gee'), '56828G - Batería 277', 0, 2, 1),
((SELECT id FROM wp_billetera_categorias WHERE slug = 'baterias' AND linea_codigo = 'gee'), 'JMC105D31L - Batería 303', 0, 3, 1),
-- JMC Baterías
((SELECT id FROM wp_billetera_categorias WHERE slug = 'baterias' AND linea_codigo = 'jmc'), 'JCM105D31R - Batería 303', 0, 1, 1);

-- PASO 9: INSERTAR SUBCATEGORÍAS (i3000+)
-- ============================================================================
INSERT IGNORE INTO wp_billetera_subcategorias (categoria_id, nombre, por_unidad, orden_display, activo) VALUES
-- Hyundai i3000+
((SELECT id FROM wp_billetera_categorias WHERE slug = 'i3000' AND linea_codigo = 'hyu'), 'i3000+ GASOL. HYUNDAI', 0, 1, 1),
((SELECT id FROM wp_billetera_categorias WHERE slug = 'i3000' AND linea_codigo = 'hyu'), 'i3000+ DIESEL 500ml HYUNDAI', 0, 2, 1),
((SELECT id FROM wp_billetera_categorias WHERE slug = 'i3000' AND linea_codigo = 'hyu'), 'i3000+ MAQUILADO (SIN LOGO HYUNDAI)', 0, 3, 1),
((SELECT id FROM wp_billetera_categorias WHERE slug = 'i3000' AND linea_codigo = 'hyu'), 'i3000+ GASOL. -VOLVO (SIN LOGO HYUNDAI)', 0, 4, 1),
((SELECT id FROM wp_billetera_categorias WHERE slug = 'i3000' AND linea_codigo = 'hyu'), 'i3000+ GASOL. JLRO (SIN LOGO HYUNDAI)', 0, 5, 1),
-- Geely i3000+
((SELECT id FROM wp_billetera_categorias WHERE slug = 'i3000' AND linea_codigo = 'gee'), 'I3000+ GASOL. (SIN LOGO HYUNDAI)', 0, 1, 1),
-- JMC i3000+
((SELECT id FROM wp_billetera_categorias WHERE slug = 'i3000' AND linea_codigo = 'jmc'), 'I3000+ DIESEL (SIN LOGO HYUNDAI)', 0, 1, 1);

-- PASO 10: INSERTAR SUBCATEGORÍAS (Aditivos Liquimoly)
-- ============================================================================
INSERT IGNORE INTO wp_billetera_subcategorias (categoria_id, nombre, por_unidad, orden_display, activo) VALUES
-- Hyundai Aditivos Liquimoly
((SELECT id FROM wp_billetera_categorias WHERE slug = 'aditivos-liquimoly' AND linea_codigo = 'hyu'), 'Pack Inyección + Obturador (Vehículos Diesel)', 0, 1, 1),
((SELECT id FROM wp_billetera_categorias WHERE slug = 'aditivos-liquimoly' AND linea_codigo = 'hyu'), 'PACK MANTEMIENTO DE FRENOS (3 Productos)', 0, 2, 1),
((SELECT id FROM wp_billetera_categorias WHERE slug = 'aditivos-liquimoly' AND linea_codigo = 'hyu'), 'PACK DPF Preventivo', 0, 3, 1),
((SELECT id FROM wp_billetera_categorias WHERE slug = 'aditivos-liquimoly' AND linea_codigo = 'hyu'), 'PACK DE INYECCIÓN GASOLINA (2 Productos)', 0, 4, 1),
((SELECT id FROM wp_billetera_categorias WHERE slug = 'aditivos-liquimoly' AND linea_codigo = 'hyu'), 'PACK DE INYECCIÓN DIESEL (2 Productos)', 0, 5, 1),
((SELECT id FROM wp_billetera_categorias WHERE slug = 'aditivos-liquimoly' AND linea_codigo = 'hyu'), 'PACK DE LIMPIEZA DE INYECCIÓN Y OBTURADOR (3 Productos)', 0, 6, 1),
((SELECT id FROM wp_billetera_categorias WHERE slug = 'aditivos-liquimoly' AND linea_codigo = 'hyu'), 'PACK MANTENIMIENTO DE MOTOR (2 Productos, Gasolina & Diesel)', 0, 7, 1),
((SELECT id FROM wp_billetera_categorias WHERE slug = 'aditivos-liquimoly' AND linea_codigo = 'hyu'), 'PACK MANTENIMIENTO RADIADOR (2 Productos)', 0, 8, 1),
((SELECT id FROM wp_billetera_categorias WHERE slug = 'aditivos-liquimoly' AND linea_codigo = 'hyu'), 'PACK MANTENIMIENTO DIRECCIÓN HIDRÁULICA (2 Productos)', 0, 9, 1),
((SELECT id FROM wp_billetera_categorias WHERE slug = 'aditivos-liquimoly' AND linea_codigo = 'hyu'), 'Pack Inyección Diesel', 0, 10, 1),
((SELECT id FROM wp_billetera_categorias WHERE slug = 'aditivos-liquimoly' AND linea_codigo = 'hyu'), 'Pack Inyección Gasolina', 0, 11, 1),
((SELECT id FROM wp_billetera_categorias WHERE slug = 'aditivos-liquimoly' AND linea_codigo = 'hyu'), 'KLIMA REFRESH: LIMPIEZA DE A/C', 0, 12, 1),
-- JMC Aditivos Liquimoly
((SELECT id FROM wp_billetera_categorias WHERE slug = 'aditivos-liquimoly' AND linea_codigo = 'jmc'), 'PACK DE INYECCIÓN DIESEL (2 Productos)', 0, 1, 1),
-- Geely Aditivos Liquimoly
((SELECT id FROM wp_billetera_categorias WHERE slug = 'aditivos-liquimoly' AND linea_codigo = 'gee'), 'PACK DE INYECCIÓN GASOLINA (2 Productos)', 0, 1, 1),
((SELECT id FROM wp_billetera_categorias WHERE slug = 'aditivos-liquimoly' AND linea_codigo = 'gee'), 'PACK DE LIMPIEZA DE INYECCIÓN Y OBTURADOR (3 Productos)', 0, 2, 1),
((SELECT id FROM wp_billetera_categorias WHERE slug = 'aditivos-liquimoly' AND linea_codigo = 'gee'), 'PACK MANTENIMIENTO DE MOTOR (2 Productos, Gasolina & Diesel)', 0, 3, 1),
((SELECT id FROM wp_billetera_categorias WHERE slug = 'aditivos-liquimoly' AND linea_codigo = 'gee'), 'PACK MANTENIMIENTO RADIADOR (2 Productos)', 0, 4, 1),
((SELECT id FROM wp_billetera_categorias WHERE slug = 'aditivos-liquimoly' AND linea_codigo = 'gee'), 'PACK MANTEMIENTO DE FRENOS (3 Productos)', 0, 5, 1),
((SELECT id FROM wp_billetera_categorias WHERE slug = 'aditivos-liquimoly' AND linea_codigo = 'gee'), 'PACK MANTENIMIENTO DIRECCIÓN HIDRÁULICA (2 Productos)', 0, 6, 1);

-- PASO 11: INSERTAR SUBCATEGORÍAS (Glasscoat)
-- ============================================================================
INSERT IGNORE INTO wp_billetera_subcategorias (categoria_id, nombre, por_unidad, orden_display, activo) VALUES
-- Hyundai Glasscoat
((SELECT id FROM wp_billetera_categorias WHERE slug = 'glasscoat' AND linea_codigo = 'hyu'), 'HYUNDAI BRONZE PACK - GLASSCOAT', 0, 1, 1),
((SELECT id FROM wp_billetera_categorias WHERE slug = 'glasscoat' AND linea_codigo = 'hyu'), 'HYUNDAI GOLD PACK- GLASSCOAT', 0, 2, 1),
((SELECT id FROM wp_billetera_categorias WHERE slug = 'glasscoat' AND linea_codigo = 'hyu'), 'GOLD PACK- GLASSCOAT LRO (PARA REPOSICION)', 0, 3, 1),
((SELECT id FROM wp_billetera_categorias WHERE slug = 'glasscoat' AND linea_codigo = 'hyu'), 'GOLD PACK- GLASSCOAT VOLVO (PARA REPOSICION)', 0, 4, 1),
-- Geely Glasscoat
((SELECT id FROM wp_billetera_categorias WHERE slug = 'glasscoat' AND linea_codigo = 'gee'), 'GEELY BRONZE PACK - GLASSCOAT', 0, 1, 1),
((SELECT id FROM wp_billetera_categorias WHERE slug = 'glasscoat' AND linea_codigo = 'gee'), 'GEELY GOLD PACK- GLASSCOAT', 0, 2, 1);

-- PASO 12: INSERTAR SUBCATEGORÍAS (Airlife)
-- ============================================================================
INSERT IGNORE INTO wp_billetera_subcategorias (categoria_id, nombre, por_unidad, orden_display, activo) VALUES
-- Hyundai Airlife
((SELECT id FROM wp_billetera_categorias WHERE slug = 'airlife' AND linea_codigo = 'hyu'), 'Venta Servicio Airlife', 0, 1, 1);

-- PASO 13: INSERTAR SUBCATEGORÍAS (ECOEVOL)
-- ============================================================================
INSERT IGNORE INTO wp_billetera_subcategorias (categoria_id, nombre, por_unidad, orden_display, activo) VALUES
-- Hyundai ECOEVOL
((SELECT id FROM wp_billetera_categorias WHERE slug = 'ecoevol' AND linea_codigo = 'hyu'), 'PURIFICADOR DE DIESEL ECOEVOL F+', 0, 1, 1),
((SELECT id FROM wp_billetera_categorias WHERE slug = 'ecoevol' AND linea_codigo = 'hyu'), 'CATALIZADOR DE COMBUSTIBLE ECOEVOL C', 1, 2, 1);

-- ============================================================================
-- PASO 14: INSERTAR COMISIONES (TODOS = NULL)
-- ============================================================================

INSERT IGNORE INTO wp_billetera_comisiones (subcategoria_id, distribuidor_id, rol, monto, moneda, activo) VALUES
(1,NULL,'asesor',45,'USD',1),
(2,NULL,'asesor',35,'USD',1),
(3,NULL,'asesor',45,'USD',1),
(4,NULL,'asesor',35,'USD',1),
(5,NULL,'asesor',45,'USD',1),
(6,NULL,'asesor',35,'USD',1),
(7,NULL,'asesor',50,'USD',1),
(8,NULL,'asesor',12,'USD',1),
(9,NULL,'asesor',55,'USD',1),
(10,NULL,'asesor',13,'USD',1),
(11,NULL,'asesor',10,'SOL',1),
(12,NULL,'asesor',10,'SOL',1),
(13,NULL,'asesor',10,'SOL',1),
(14,NULL,'asesor',10,'USD',1),
(15,NULL,'asesor',20,'USD',1),
(16,NULL,'asesor',30,'USD',1),
(17,NULL,'asesor',40,'USD',1),
(18,NULL,'asesor',50,'USD',1),
(19,NULL,'asesor',10,'USD',1),
(20,NULL,'asesor',20,'USD',1),
(21,NULL,'asesor',30,'USD',1),
(22,NULL,'asesor',40,'USD',1),
(23,NULL,'asesor',50,'USD',1),
(24,NULL,'asesor',20,'USD',1),
(25,NULL,'asesor',30,'USD',1),
(26,NULL,'asesor',40,'USD',1),
(27,NULL,'asesor',50,'USD',1),
(28,NULL,'asesor',15,'SOL',1),
(29,NULL,'asesor',18,'SOL',1),
(30,NULL,'asesor',18,'SOL',1),
(31,NULL,'asesor',20,'SOL',1),
(32,NULL,'asesor',15,'SOL',1),
(33,NULL,'asesor',18,'SOL',1),
(34,NULL,'asesor',20,'SOL',1),
(35,NULL,'asesor',25,'SOL',1),
(36,NULL,'asesor',25,'SOL',1),
(37,NULL,'asesor',25,'SOL',1),
(38,NULL,'asesor',30,'SOL',1),
(39,NULL,'asesor',20,'SOL',1),
(40,NULL,'asesor',18,'SOL',1),
(41,NULL,'asesor',25,'SOL',1),
(42,NULL,'asesor',13,'SOL',1),
(43,NULL,'asesor',7,'SOL',1),
(44,NULL,'asesor',7,'SOL',1),
(45,NULL,'asesor',7,'SOL',1),
(46,NULL,'asesor',7,'SOL',1),
(47,NULL,'asesor',7,'SOL',1),
(48,NULL,'asesor',7,'SOL',1),
(49,NULL,'asesor',7,'SOL',1),
(50,NULL,'asesor',11,'SOL',1),
(51,NULL,'asesor',9,'SOL',1),
(52,NULL,'asesor',11,'SOL',1),
(53,NULL,'asesor',11,'SOL',1),
(54,NULL,'asesor',9,'SOL',1),
(55,NULL,'asesor',9,'SOL',1),
(56,NULL,'asesor',9,'SOL',1),
(57,NULL,'asesor',9,'SOL',1),
(58,NULL,'asesor',9,'SOL',1),
(59,NULL,'asesor',9,'SOL',1),
(60,NULL,'asesor',9,'SOL',1),
(61,NULL,'asesor',9,'SOL',1),
(62,NULL,'asesor',9,'SOL',1),
(63,NULL,'asesor',7,'SOL',1),
(64,NULL,'asesor',9,'SOL',1),
(65,NULL,'asesor',9,'SOL',1),
(66,NULL,'asesor',9,'SOL',1),
(67,NULL,'asesor',9,'SOL',1),
(68,NULL,'asesor',9,'SOL',1),
(69,NULL,'asesor',9,'SOL',1),
(70,NULL,'asesor',9,'SOL',1),
(71,NULL,'asesor',70,'SOL',1),
(72,NULL,'asesor',120,'SOL',1),
(73,NULL,'asesor',120,'SOL',1),
(74,NULL,'asesor',120,'SOL',1),
(75,NULL,'asesor',70,'SOL',1),
(76,NULL,'asesor',120,'SOL',1),
(77,NULL,'asesor',7,'SOL',1),
(78,NULL,'asesor',40,'SOL',1),
(79,NULL,'asesor',7,'SOL',1),
(80,NULL,'asesor',20,'SOL',1),
(81,NULL,'asesor',49,'SOL',1),
(82,NULL,'asesor',46,'SOL',1),
(83,NULL,'asesor',42,'SOL',1),
(1,NULL,'jefe_venta',7.5,'USD',1),
(2,NULL,'jefe_venta',5,'USD',1),
(3,NULL,'jefe_venta',7.5,'USD',1),
(4,NULL,'jefe_venta',5,'USD',1),
(5,NULL,'jefe_venta',7.5,'USD',1),
(6,NULL,'jefe_venta',5,'USD',1),
(7,NULL,'jefe_venta',0,'USD',1),
(8,NULL,'jefe_venta',0,'USD',1),
(9,NULL,'jefe_venta',0,'USD',1),
(10,NULL,'jefe_venta',0,'USD',1),
(11,NULL,'jefe_venta',2,'SOL',1),
(12,NULL,'jefe_venta',2,'SOL',1),
(13,NULL,'jefe_venta',2,'SOL',1),
(14,NULL,'jefe_venta',0,'USD',1),
(15,NULL,'jefe_venta',0,'USD',1),
(16,NULL,'jefe_venta',0,'USD',1),
(17,NULL,'jefe_venta',0,'USD',1),
(18,NULL,'jefe_venta',0,'USD',1),
(19,NULL,'jefe_venta',0,'USD',1),
(20,NULL,'jefe_venta',0,'USD',1),
(21,NULL,'jefe_venta',0,'USD',1),
(22,NULL,'jefe_venta',0,'USD',1),
(23,NULL,'jefe_venta',0,'USD',1),
(24,NULL,'jefe_venta',0,'USD',1),
(25,NULL,'jefe_venta',0,'USD',1),
(26,NULL,'jefe_venta',0,'USD',1),
(27,NULL,'jefe_venta',0,'USD',1),
(28,NULL,'jefe_venta',6,'SOL',1),
(29,NULL,'jefe_venta',6,'SOL',1),
(30,NULL,'jefe_venta',6,'SOL',1),
(31,NULL,'jefe_venta',7,'SOL',1),
(32,NULL,'jefe_venta',6,'SOL',1),
(33,NULL,'jefe_venta',6,'SOL',1),
(34,NULL,'jefe_venta',7,'SOL',1),
(35,NULL,'jefe_venta',10,'SOL',1),
(36,NULL,'jefe_venta',10,'SOL',1),
(37,NULL,'jefe_venta',10,'SOL',1),
(38,NULL,'jefe_venta',10,'SOL',1),
(39,NULL,'jefe_venta',7,'SOL',1),
(40,NULL,'jefe_venta',6,'SOL',1),
(41,NULL,'jefe_venta',10,'SOL',1),
(42,NULL,'jefe_venta',5,'SOL',1),
(43,NULL,'jefe_venta',2,'SOL',1),
(44,NULL,'jefe_venta',2,'SOL',1),
(45,NULL,'jefe_venta',2,'SOL',1),
(46,NULL,'jefe_venta',2,'SOL',1),
(47,NULL,'jefe_venta',2,'SOL',1),
(48,NULL,'jefe_venta',2,'SOL',1),
(49,NULL,'jefe_venta',2,'SOL',1),
(50,NULL,'jefe_venta',2,'SOL',1),
(51,NULL,'jefe_venta',2,'SOL',1),
(52,NULL,'jefe_venta',2,'SOL',1),
(53,NULL,'jefe_venta',2,'SOL',1),
(54,NULL,'jefe_venta',2,'SOL',1),
(55,NULL,'jefe_venta',2,'SOL',1),
(56,NULL,'jefe_venta',2,'SOL',1),
(57,NULL,'jefe_venta',2,'SOL',1),
(58,NULL,'jefe_venta',2,'SOL',1),
(59,NULL,'jefe_venta',2,'SOL',1),
(60,NULL,'jefe_venta',2,'SOL',1),
(61,NULL,'jefe_venta',2,'SOL',1),
(62,NULL,'jefe_venta',2,'SOL',1),
(63,NULL,'jefe_venta',2,'SOL',1),
(64,NULL,'jefe_venta',2,'SOL',1),
(65,NULL,'jefe_venta',2,'SOL',1),
(66,NULL,'jefe_venta',2,'SOL',1),
(67,NULL,'jefe_venta',2,'SOL',1),
(68,NULL,'jefe_venta',2,'SOL',1),
(69,NULL,'jefe_venta',2,'SOL',1),
(70,NULL,'jefe_venta',2,'SOL',1),
(71,NULL,'jefe_venta',15,'SOL',1),
(72,NULL,'jefe_venta',30,'SOL',1),
(73,NULL,'jefe_venta',30,'SOL',1),
(74,NULL,'jefe_venta',30,'SOL',1),
(75,NULL,'jefe_venta',15,'SOL',1),
(76,NULL,'jefe_venta',30,'SOL',1),
(77,NULL,'jefe_venta',2,'SOL',1),
(78,NULL,'jefe_venta',10,'SOL',1),
(79,NULL,'jefe_venta',2,'SOL',1),
(80,NULL,'jefe_venta',0,'SOL',1),
(81,NULL,'jefe_venta',0,'SOL',1),
(82,NULL,'jefe_venta',0,'SOL',1),
(83,NULL,'jefe_venta',0,'SOL',1)

-- ============================================================================
-- PASO 14: INSERTAR COMISIONES (MANASA = 12) 
-- ============================================================================
INSERT IGNORE INTO wp_billetera_comisiones (subcategoria_id, distribuidor_id, rol, monto, moneda, activo) VALUES

(1,12,'asesor',35,'USD',1),
(2,12,'asesor',35,'USD',1),
(3,12,'asesor',35,'USD',1),
(4,12,'asesor',35,'USD',1),
(5,12,'asesor',35,'USD',1),
(6,12,'asesor',35,'USD',1),
(14,12,'asesor',10,'USD',1),
(15,12,'asesor',20,'USD',1),
(16,12,'asesor',30,'USD',1),
(17,12,'asesor',40,'USD',1),
(18,12,'asesor',50,'USD',1),
(19,12,'asesor',10,'USD',1),
(20,12,'asesor',20,'USD',1),
(21,12,'asesor',30,'USD',1),
(22,12,'asesor',40,'USD',1),
(23,12,'asesor',50,'USD',1),
(24,12,'asesor',20,'USD',1),
(25,12,'asesor',30,'USD',1),
(26,12,'asesor',40,'USD',1),
(27,12,'asesor',50,'USD',1),
(77,12,'asesor',8.25,'SOL',1),
(1,12,'jefe_venta',7.5,'USD',1),
(2,12,'jefe_venta',5,'USD',1),
(3,12,'jefe_venta',7.5,'USD',1),
(4,12,'jefe_venta',5,'USD',1),
(5,12,'jefe_venta',7.5,'USD',1),
(6,12,'jefe_venta',5,'USD',1),
(14,12,'jefe_venta',5,'USD',1),
(15,12,'jefe_venta',7.5,'USD',1),
(16,12,'jefe_venta',10,'USD',1),
(17,12,'jefe_venta',12.5,'USD',1),
(18,12,'jefe_venta',15,'USD',1),
(19,12,'jefe_venta',3,'USD',1),
(20,12,'jefe_venta',6,'USD',1),
(21,12,'jefe_venta',7.5,'USD',1),
(22,12,'jefe_venta',10,'USD',1),
(23,12,'jefe_venta',12,'USD',1),
(24,12,'jefe_venta',3,'USD',1),
(25,12,'jefe_venta',6,'USD',1),
(26,12,'jefe_venta',7.5,'USD',1),
(27,12,'jefe_venta',10,'USD',1),
(77,12,'jefe_venta',0,'SOL',1);

-- ============================================================================
-- PASO 14: INSERTAR COMISIONES (AUTOMOTRIZ INCAMOTORS S.A.C = 13) 
-- ============================================================================
INSERT IGNORE INTO wp_billetera_comisiones (subcategoria_id, distribuidor_id, rol, monto, moneda, activo) VALUES
(50,13,'asesor',14,'SOL',1),
(51,13,'asesor',14,'SOL',1),
(52,13,'asesor',14,'SOL',1),
(53,13,'asesor',14,'SOL',1),
(54,13,'asesor',14,'SOL',1),
(55,13,'asesor',14,'SOL',1),
(56,13,'asesor',14,'SOL',1),
(57,13,'asesor',14,'SOL',1),
(58,13,'asesor',14,'SOL',1),
(59,13,'asesor',14,'SOL',1),
(60,13,'asesor',14,'SOL',1),
(61,13,'asesor',14,'SOL',1),
(62,13,'asesor',14,'SOL',1),
(64,13,'asesor',14,'SOL',1),
(65,13,'asesor',14,'SOL',1),
(66,13,'asesor',14,'SOL',1),
(67,13,'asesor',14,'SOL',1),
(68,13,'asesor',14,'SOL',1),
(69,13,'asesor',14,'SOL',1),
(70,13,'asesor',14,'SOL',1),
(77,13,'asesor',3.5,'SOL',1),
(50,13,'jefe_venta',14,'SOL',1),
(51,13,'jefe_venta',14,'SOL',1),
(52,13,'jefe_venta',14,'SOL',1),
(53,13,'jefe_venta',14,'SOL',1),
(54,13,'jefe_venta',14,'SOL',1),
(55,13,'jefe_venta',14,'SOL',1),
(56,13,'jefe_venta',14,'SOL',1),
(57,13,'jefe_venta',14,'SOL',1),
(58,13,'jefe_venta',14,'SOL',1),
(59,13,'jefe_venta',14,'SOL',1),
(60,13,'jefe_venta',14,'SOL',1),
(61,13,'jefe_venta',14,'SOL',1),
(62,13,'jefe_venta',14,'SOL',1),
(64,13,'jefe_venta',14,'SOL',1),
(65,13,'jefe_venta',14,'SOL',1),
(66,13,'jefe_venta',14,'SOL',1),
(67,13,'jefe_venta',14,'SOL',1),
(68,13,'jefe_venta',14,'SOL',1),
(69,13,'jefe_venta',14,'SOL',1),
(70,13,'jefe_venta',14,'SOL',1),
(77,13,'jefe_venta',0,'SOL',1);
-- ============================================================================
-- PASO 14: INSERTAR COMISIONES (MAQUINARIAS → 14) 
-- ============================================================================
INSERT IGNORE INTO wp_billetera_comisiones (subcategoria_id, distribuidor_id, rol, monto, moneda, activo) VALUES
(77,14,'asesor',3.5,'SOL',1),
(77,14,'jefe_venta',0,'SOL',1);
-- ============================================================================
-- PASO 14: INSERTAR COMISIONES (SAN ANTONIO TRADE → 15) 
-- ============================================================================
INSERT IGNORE INTO wp_billetera_comisiones (subcategoria_id, distribuidor_id, rol, monto, moneda, activo) VALUES
(77,15,'asesor',4,'SOL',1),
(77,15,'jefe_venta',0,'SOL',1);
-- ============================================================================
-- PASO 14: INSERTAR COMISIONES (SPORTWAGEN → 16) 
-- ============================================================================
INSERT IGNORE INTO wp_billetera_comisiones (subcategoria_id, distribuidor_id, rol, monto, moneda, activo) VALUES
(77,16,'asesor',10,'SOL',1),
(77,16,'jefe_venta',0,'SOL',1);

-- ============================================================================
-- PASO 15: DUPLICAR LA LÍNEA 'hyu' (Hyundai Autos) HACIA 'hcv' (Hyundai Camiones)
-- Se clonan categorías, subcategorías y comisiones para que ambas líneas
-- arranquen con el mismo catálogo. Ejecutar una sola vez.
-- ============================================================================

-- 15.1 Categorías hyu -> hcv
INSERT IGNORE INTO wp_billetera_categorias (linea_codigo, nombre, slug, orden_display, activo)
SELECT 'hcv', nombre, slug, orden_display, activo
FROM wp_billetera_categorias
WHERE linea_codigo = 'hyu';

-- 15.2 Subcategorías hyu -> hcv
INSERT INTO wp_billetera_subcategorias (categoria_id, nombre, por_unidad, orden_display, activo)
SELECT chcv.id, s.nombre, s.por_unidad, s.orden_display, s.activo
FROM wp_billetera_categorias chcv
JOIN wp_billetera_categorias chyu ON chyu.linea_codigo = 'hyu' AND chyu.slug = chcv.slug
JOIN wp_billetera_subcategorias s ON s.categoria_id = chyu.id
WHERE chcv.linea_codigo = 'hcv';

-- 15.3 Comisiones hyu -> hcv
INSERT INTO wp_billetera_comisiones (subcategoria_id, distribuidor_id, rol, monto, moneda, activo)
SELECT shcv.id, com.distribuidor_id, com.rol, com.monto, com.moneda, com.activo
FROM wp_billetera_subcategorias shcv
JOIN wp_billetera_categorias chcv ON chcv.id = shcv.categoria_id AND chcv.linea_codigo = 'hcv'
JOIN wp_billetera_categorias chyu ON chyu.linea_codigo = 'hyu' AND chyu.slug = chcv.slug
JOIN wp_billetera_subcategorias shyu ON shyu.categoria_id = chyu.id AND shyu.nombre = shcv.nombre
JOIN wp_billetera_comisiones com ON com.subcategoria_id = shyu.id;