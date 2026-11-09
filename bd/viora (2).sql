-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Servidor: 127.0.0.1
-- Tiempo de generación: 09-11-2026 a las 01:13:52
-- Versión del servidor: 10.4.32-MariaDB
-- Versión de PHP: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Base de datos: `viora`
--

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `apartamento`
--

CREATE TABLE `apartamento` (
  `id_apartamentos` bigint(20) NOT NULL,
  `edificio_id` bigint(20) NOT NULL,
  `numero` varchar(20) NOT NULL,
  `piso` int(11) DEFAULT NULL,
  `habitaciones` int(11) DEFAULT NULL,
  `banos` int(11) DEFAULT NULL,
  `metros_cuadrados` decimal(10,2) DEFAULT NULL,
  `descripcion` text DEFAULT NULL,
  `estado` varchar(30) DEFAULT 'Disponible',
  `activo` tinyint(1) DEFAULT 1,
  `imagen` varchar(255) DEFAULT NULL,
  `precio_alquiler` decimal(12,2) DEFAULT NULL,
  `disponible` tinyint(1) DEFAULT 1,
  `tipo_operacion` enum('Alquiler','Venta','Ambos') NOT NULL DEFAULT 'Alquiler',
  `precio_venta` decimal(14,2) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Volcado de datos para la tabla `apartamento`
--

INSERT INTO `apartamento` (`id_apartamentos`, `edificio_id`, `numero`, `piso`, `habitaciones`, `banos`, `metros_cuadrados`, `descripcion`, `estado`, `activo`, `imagen`, `precio_alquiler`, `disponible`, `tipo_operacion`, `precio_venta`) VALUES
(1, 1, 'A-101', 1, 2, 1, 85.00, 'Apartamento luminoso con balcón y parqueo', 'Ocupado', 1, '05e57e069be5000d5ac8eb74499fb147.jpg', 18000.00, 0, 'Alquiler', NULL),
(2, 1, 'A-202', 2, 3, 2, 120.00, 'Apartamento amplio con cocina moderna', 'Ocupado', 1, '72ebde6f5a6a1bd6dc4bfb2da9aafeaf.jpg', 25000.00, 0, 'Alquiler', NULL),
(3, 2, 'B-301', 3, 3, 2, 135.00, 'Apartamento con vista panorámica', 'Ocupado', 1, 'eca26ed0083dca6d967578d72e05bd1d.jpg', NULL, 1, 'Venta', 6500000.00),
(4, 3, 'P-104', 1, 2, 2, 95.00, 'Apartamento con terraza privada', 'Ocupado', 1, '4a8b17fb14e1fd9af4e6a561c59da6b6.jpg', 20000.00, 1, 'Alquiler', NULL),
(5, 4, 'VM-201', 2, 1, 1, 60.00, 'Estudio con vista al mar', 'Ocupado', 1, '9eca345c7501fecb0771a7808480e3a7.jpg', 14000.00, 1, 'Alquiler', NULL),
(6, 5, 'PC-501', 5, 2, 1, 78.00, 'Apartamento céntrico cerca de comercios', 'Ocupado', 1, '1bcfbbbf6cb664716506439bb0132d91.jpg', 16500.00, 1, 'Alquiler', NULL),
(7, 6, 'AB-102', 1, 3, 2, 140.00, 'Apartamento familiar junto al parque', 'Disponible', 1, 'b4c1818a608b7e8dc94256e30f83833a.jpg', 30000.00, 1, 'Ambos', 7800000.00),
(8, 6, 'AB-203', 2, 2, 2, 100.00, 'Apartamento remodelado con área de lavado', 'Disponible', 1, 'b1208d3e2c444353352f6be76d7c4981.jpg', 22000.00, 1, 'Ambos', 5200000.00),
(9, 3, 'P-305', 3, 4, 3, 190.00, 'Penthouse con terraza y jacuzzi', 'Disponible', 1, '2f194729cf0100a9a9b67c3719f4a84a.jpg', NULL, 1, 'Venta', 9500000.00);

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `comprobante_fiscal`
--

CREATE TABLE `comprobante_fiscal` (
  `id_comprobantes` bigint(20) NOT NULL,
  `pago_id` bigint(20) NOT NULL,
  `numero_comprobante` varchar(50) NOT NULL,
  `tipo_comprobante` varchar(30) DEFAULT NULL,
  `rnc_emisor` varchar(30) DEFAULT NULL,
  `rnc_receptor` varchar(30) DEFAULT NULL,
  `subtotal` decimal(18,2) NOT NULL,
  `impuesto` decimal(18,2) DEFAULT 0.00,
  `total` decimal(18,2) NOT NULL,
  `fecha_emision` timestamp NOT NULL DEFAULT current_timestamp(),
  `ruta_archivo` varchar(255) DEFAULT NULL,
  `estado` varchar(30) DEFAULT 'Emitido',
  `activo` tinyint(1) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Volcado de datos para la tabla `comprobante_fiscal`
--

INSERT INTO `comprobante_fiscal` (`id_comprobantes`, `pago_id`, `numero_comprobante`, `tipo_comprobante`, `rnc_emisor`, `rnc_receptor`, `subtotal`, `impuesto`, `total`, `fecha_emision`, `ruta_archivo`, `estado`, `activo`) VALUES
(1, 1, 'DEMO-NCF-001', 'Prueba', '000000001', NULL, 18000.00, 0.00, 18000.00, '2026-10-17 14:20:51', NULL, 'Emitido', 1),
(2, 2, 'DEMO-NCF-002', 'Prueba', '000000001', NULL, 2500.00, 0.00, 2500.00, '2026-10-17 14:20:51', NULL, 'Emitido', 1),
(3, 3, 'DEMO-NCF-003', 'Prueba', '000000001', NULL, 10000.00, 0.00, 10000.00, '2026-10-17 14:20:51', NULL, 'Emitido', 1),
(4, 4, 'DEMO-NCF-004', 'Prueba', '000000001', NULL, 14000.00, 0.00, 14000.00, '2026-10-17 14:20:51', NULL, 'Emitido', 1),
(5, 5, 'DEMO-NCF-005', 'Prueba', '000000001', NULL, 40000.00, 0.00, 40000.00, '2026-10-17 14:20:51', NULL, 'Emitido', 1);

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `concepto_pago`
--

CREATE TABLE `concepto_pago` (
  `id_conceptos_pago` bigint(20) NOT NULL,
  `nombre` varchar(80) NOT NULL,
  `descripcion` varchar(255) DEFAULT NULL,
  `activo` tinyint(1) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Volcado de datos para la tabla `concepto_pago`
--

INSERT INTO `concepto_pago` (`id_conceptos_pago`, `nombre`, `descripcion`, `activo`) VALUES
(1, 'Alquiler', 'Pago mensual de alquiler', 1),
(2, 'Mantenimiento', 'Cuota de mantenimiento del condominio', 1),
(3, 'Servicios', 'Servicios comunes del condominio', 1),
(4, 'Depósito', 'Depósito de garantía del contrato', 1),
(5, 'Mora', 'Recargo por pago atrasado', 1);

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `condominio`
--

CREATE TABLE `condominio` (
  `id_condominios` bigint(20) NOT NULL,
  `nombre` varchar(100) NOT NULL,
  `descripcion` text DEFAULT NULL,
  `direccion` varchar(255) NOT NULL,
  `ciudad` varchar(100) DEFAULT NULL,
  `provincia` varchar(100) DEFAULT NULL,
  `estado` varchar(30) DEFAULT 'Activo',
  `activo` tinyint(1) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Volcado de datos para la tabla `condominio`
--

INSERT INTO `condominio` (`id_condominios`, `nombre`, `descripcion`, `direccion`, `ciudad`, `provincia`, `estado`, `activo`) VALUES
(1, 'Torre del Sol', 'Residencial de lujo con piscina y gimnasio', 'Av. Winston Churchill #45', 'Santo Domingo', 'Distrito Nacional', 'Activo', 1),
(2, 'Residencial Las Palmas', 'Complejo familiar con amplias áreas verdes', 'Calle Las Palmas #12', 'Santiago', 'Santiago', 'Activo', 1),
(3, 'Villa Mar', 'Apartamentos frente al mar con acceso a playa', 'Autopista Las Américas Km 22', 'Boca Chica', 'Santo Domingo', 'Activo', 1),
(4, 'Plaza Central', 'Torre céntrica cerca de comercios y servicios', 'Calle Duarte #101', 'La Vega', 'La Vega', 'Activo', 1),
(5, 'Altos del Parque', 'Condominio con parque infantil y seguridad 24 horas', 'Av. Los Próceres #88', 'Santo Domingo Este', 'Santo Domingo', 'Activo', 1);

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `contrato`
--

CREATE TABLE `contrato` (
  `id_contratos` bigint(20) NOT NULL,
  `apartamento_id` bigint(20) NOT NULL,
  `propiedad_id` bigint(20) DEFAULT NULL,
  `numero_contrato` varchar(50) NOT NULL,
  `fecha_inicio` date NOT NULL,
  `fecha_fin` date DEFAULT NULL,
  `monto_alquiler` decimal(18,2) NOT NULL,
  `deposito` decimal(18,2) DEFAULT 0.00,
  `condiciones` text DEFAULT NULL,
  `estado` varchar(30) DEFAULT 'Activo',
  `activo` tinyint(1) DEFAULT 1,
  `solicitud_id` bigint(20) DEFAULT NULL,
  `persona_id` bigint(20) DEFAULT NULL,
  `tipo_operacion` enum('Alquiler','Venta') NOT NULL DEFAULT 'Alquiler',
  `archivo_contrato` varchar(255) DEFAULT NULL,
  `monto_mantenimiento` decimal(18,2) NOT NULL DEFAULT 0.00
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Volcado de datos para la tabla `contrato`
--

INSERT INTO `contrato` (`id_contratos`, `apartamento_id`, `propiedad_id`, `numero_contrato`, `fecha_inicio`, `fecha_fin`, `monto_alquiler`, `deposito`, `condiciones`, `estado`, `activo`, `solicitud_id`, `persona_id`, `tipo_operacion`, `archivo_contrato`, `monto_mantenimiento`) VALUES
(1, 1, NULL, 'CONT-001', '2025-10-01', '2026-10-01', 18000.00, 36000.00, 'Pago dentro de los primeros 10 días de cada mes.', 'Activo', 1, 1, 8, 'Alquiler', NULL, 2500.00),
(2, 2, NULL, 'CONT-002', '2025-11-01', '2026-11-01', 25000.00, 50000.00, 'Pago dentro de los primeros 10 días de cada mes.', 'Activo', 1, 2, 9, 'Alquiler', NULL, 2500.00),
(3, 3, NULL, 'CONT-003', '2026-09-15', '2026-09-30', 6500000.00, 0.00, 'Compraventa pagada al contado.', 'Activo', 1, 3, 10, 'Venta', NULL, 3000.00),
(4, 4, NULL, 'CONT-004', '2026-01-15', '2027-01-15', 20000.00, 40000.00, 'Pago dentro de los primeros 10 días de cada mes.', 'Activo', 1, 4, 12, 'Alquiler', NULL, 2500.00),
(5, 5, NULL, 'CONT-005', '2026-03-01', '2027-03-01', 14000.00, 28000.00, 'Pago dentro de los primeros 10 días de cada mes.', 'Activo', 1, 5, 13, 'Alquiler', NULL, 2000.00),
(6, 6, NULL, 'CONT-006', '2026-07-01', '2026-12-01', 16500.00, 33000.00, 'Contrato por seis meses, renovable.', 'Activo', 1, 6, 8, 'Alquiler', NULL, 2500.00),
(7, 8, NULL, 'CONT-009', '2026-10-17', '2027-07-16', 5200000.00, 100000.00, '', 'Activo', 1, 9, 15, 'Venta', 'f6c5ee17dd5a1244d38a03ff6107eb5f8ae1535f.pdf', 2500.00);

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `contrato_detalle_persona`
--

CREATE TABLE `contrato_detalle_persona` (
  `id_contrato_persona` bigint(20) NOT NULL,
  `contrato_id` bigint(20) NOT NULL,
  `persona_id` bigint(20) NOT NULL,
  `tipo_parte` varchar(30) NOT NULL,
  `activo` tinyint(1) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Volcado de datos para la tabla `contrato_detalle_persona`
--

INSERT INTO `contrato_detalle_persona` (`id_contrato_persona`, `contrato_id`, `persona_id`, `tipo_parte`, `activo`) VALUES
(1, 1, 8, 'Inquilino', 1),
(2, 2, 9, 'Inquilino', 1),
(3, 3, 10, 'Comprador', 1),
(4, 4, 12, 'Inquilino', 1),
(5, 5, 13, 'Inquilino', 1),
(6, 6, 8, 'Inquilino', 1);

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `conversacion_ia`
--

CREATE TABLE `conversacion_ia` (
  `id_conversaciones` bigint(20) NOT NULL,
  `usuario_id` bigint(20) NOT NULL,
  `fecha_inicio` timestamp NOT NULL DEFAULT current_timestamp(),
  `estado` varchar(30) DEFAULT 'Activa',
  `activo` tinyint(1) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Volcado de datos para la tabla `conversacion_ia`
--

INSERT INTO `conversacion_ia` (`id_conversaciones`, `usuario_id`, `fecha_inicio`, `estado`, `activo`) VALUES
(1, 1, '2026-10-16 13:00:00', 'Activa', 1),
(2, 6, '2026-10-16 14:00:00', 'Activa', 1),
(3, 7, '2026-10-16 15:00:00', 'Finalizada', 1),
(4, 9, '2026-10-17 12:30:00', 'Activa', 1),
(5, 10, '2026-10-17 13:45:00', 'Finalizada', 1);

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `documento`
--

CREATE TABLE `documento` (
  `id_documentos` bigint(20) NOT NULL,
  `contrato_id` bigint(20) DEFAULT NULL,
  `persona_id` bigint(20) DEFAULT NULL,
  `nombre` varchar(150) NOT NULL,
  `tipo_documento` varchar(50) DEFAULT NULL,
  `ruta_archivo` varchar(255) NOT NULL,
  `fecha_subida` timestamp NOT NULL DEFAULT current_timestamp(),
  `activo` tinyint(1) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Volcado de datos para la tabla `documento`
--

INSERT INTO `documento` (`id_documentos`, `contrato_id`, `persona_id`, `nombre`, `tipo_documento`, `ruta_archivo`, `fecha_subida`, `activo`) VALUES
(1, 1, 8, 'Contrato de alquiler CONT-001', 'PDF', 'documentos/contrato001.pdf', '2026-10-17 14:20:50', 1),
(2, 2, 9, 'Contrato de alquiler CONT-002', 'PDF', 'documentos/contrato002.pdf', '2026-10-17 14:20:50', 1),
(3, 3, 10, 'Contrato de compra CONT-003', 'PDF', 'documentos/contrato003.pdf', '2026-10-17 14:20:50', 1),
(4, 4, 12, 'Contrato de alquiler CONT-004', 'PDF', 'documentos/contrato004.pdf', '2026-10-17 14:20:50', 1),
(5, 5, 13, 'Contrato de alquiler CONT-005', 'PDF', 'documentos/contrato005.pdf', '2026-10-17 14:20:50', 1);

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `edificio`
--

CREATE TABLE `edificio` (
  `id_edificios` bigint(20) NOT NULL,
  `condominio_id` bigint(20) NOT NULL,
  `nombre` varchar(100) NOT NULL,
  `cantidad_pisos` int(11) DEFAULT NULL,
  `estado` varchar(30) DEFAULT 'Activo',
  `activo` tinyint(1) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Volcado de datos para la tabla `edificio`
--

INSERT INTO `edificio` (`id_edificios`, `condominio_id`, `nombre`, `cantidad_pisos`, `estado`, `activo`) VALUES
(1, 1, 'Torre A', 12, 'Activo', 1),
(2, 1, 'Torre B', 10, 'Activo', 1),
(3, 2, 'Edificio Palmas I', 4, 'Activo', 1),
(4, 3, 'Villa Mar Norte', 6, 'Activo', 1),
(5, 4, 'Plaza Central Torre', 8, 'Activo', 1),
(6, 5, 'Altos Bloque 1', 5, 'Activo', 1);

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `factura`
--

CREATE TABLE `factura` (
  `id_facturas` bigint(20) NOT NULL,
  `numero_documento` varchar(50) NOT NULL,
  `contrato_id` bigint(20) NOT NULL,
  `persona_id` bigint(20) NOT NULL,
  `periodo` char(7) NOT NULL,
  `fecha` date NOT NULL,
  `fecha_vencimiento` date DEFAULT NULL,
  `subtotal` decimal(18,2) NOT NULL DEFAULT 0.00,
  `total` decimal(18,2) NOT NULL DEFAULT 0.00,
  `monto_pagado` decimal(18,2) NOT NULL DEFAULT 0.00,
  `saldo_pendiente` decimal(18,2) NOT NULL DEFAULT 0.00,
  `estado` enum('Borrador','Pendiente','Parcial','Pagada','Anulada') NOT NULL DEFAULT 'Borrador',
  `observaciones` text DEFAULT NULL,
  `rnc_comprador` varchar(11) DEFAULT NULL,
  `razon_social_comprador` varchar(150) DEFAULT NULL,
  `usuario_id` bigint(20) DEFAULT NULL,
  `fecha_registro` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Volcado de datos para la tabla `factura`
--

INSERT INTO `factura` (`id_facturas`, `numero_documento`, `contrato_id`, `persona_id`, `periodo`, `fecha`, `fecha_vencimiento`, `subtotal`, `total`, `monto_pagado`, `saldo_pendiente`, `estado`, `observaciones`, `rnc_comprador`, `razon_social_comprador`, `usuario_id`, `fecha_registro`) VALUES
(1, 'FAC-2026-0001', 1, 8, '2026-09', '2026-09-01', '2026-09-10', 20500.00, 20500.00, 20500.00, 0.00, 'Pagada', 'Alquiler y mantenimiento de septiembre', NULL, NULL, 1, '2026-10-17 14:20:51'),
(2, 'FAC-2026-0002', 1, 8, '2026-10', '2026-10-01', '2026-10-10', 20500.00, 20500.00, 0.00, 20500.00, 'Pendiente', 'Alquiler y mantenimiento de octubre', NULL, NULL, 1, '2026-10-17 14:20:51'),
(3, 'FAC-2026-0003', 2, 9, '2026-10', '2026-10-01', '2026-10-10', 27500.00, 27500.00, 10000.00, 17500.00, 'Parcial', 'Alquiler y mantenimiento de octubre', NULL, NULL, 1, '2026-10-17 14:20:51'),
(4, 'FAC-2026-0004', 4, 12, '2026-10', '2026-10-01', '2026-10-10', 22500.00, 22500.00, 0.00, 22500.00, 'Pendiente', 'Alquiler y mantenimiento de octubre', NULL, NULL, 1, '2026-10-17 14:20:51'),
(5, 'FAC-2026-0005', 5, 13, '2026-10', '2026-10-01', '2026-10-10', 16000.00, 16000.00, 16000.00, 0.00, 'Pagada', 'Alquiler y mantenimiento de octubre', NULL, NULL, 1, '2026-10-17 14:20:51'),
(6, 'FAC-2026-0006', 6, 8, '2026-10', '2026-10-01', '2026-10-10', 19000.00, 19000.00, 0.00, 19000.00, 'Pendiente', 'Alquiler y mantenimiento de octubre', NULL, NULL, 1, '2026-10-17 14:20:51'),
(7, 'FAC-PRUEBA-E9861D42106F', 6, 8, '2026-10', '2026-10-19', NULL, 1500.00, 1770.00, 0.00, 1770.00, 'Pendiente', 'PRUEBA TESTECF E31 - eliminar', '131234567', 'PRUEBA TESTECF', 1, '2026-10-20 01:02:54'),
(8, 'FAC-DB3EF3B5CC2211F18BBB0A0027000010', 4, 12, '2026-11', '2026-10-20', NULL, 22500.00, 26550.00, 0.00, 26550.00, 'Pendiente', '', '130481121', 'ISLAND BRONZE LTD', 1, '2026-10-20 01:09:10'),
(9, 'FAC-8DCF6431CC2311F18BBB0A0027000010', 4, 12, '2026-12', '2026-10-20', NULL, 22500.00, 26550.00, 0.00, 26550.00, 'Pendiente', '', '130481121', 'ISLAND BRONZE LTD', 1, '2026-10-20 01:14:10'),
(10, 'FAC-0C72B88ECC2411F18BBB0A0027000010', 4, 12, '2027-01', '2026-10-20', NULL, 22500.00, 26550.00, 26550.00, 0.00, 'Pagada', '', '130481121', 'ISLAND BRONZE LTD', 1, '2026-10-20 01:17:42'),
(11, 'FAC-8F01EE7DCC2511F18BBB0A0027000010', 4, 12, '2026-01', '2026-10-20', NULL, 22500.00, 26550.00, 0.00, 26550.00, 'Pendiente', 'ninguna prueba', '131587577', 'manga tu flow com srl', 1, '2026-10-20 01:28:31'),
(12, 'FAC-925E6A66CC2C11F18BBB0A0027000010', 2, 9, '2026-09', '2026-10-20', NULL, 27500.00, 32450.00, 0.00, 32450.00, 'Pendiente', '', NULL, NULL, 1, '2026-10-20 02:18:43'),
(13, 'FAC-DD636FBECC2E11F18BBB0A0027000010', 5, 13, '2026-09', '2026-10-20', NULL, 16000.00, 18880.00, 0.00, 18880.00, 'Pendiente', '', '430340537', 'centro cristiano casa real', 1, '2026-10-20 02:35:08');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `factura_detalle`
--

CREATE TABLE `factura_detalle` (
  `id_detalles` bigint(20) NOT NULL,
  `factura_id` bigint(20) NOT NULL,
  `concepto` enum('Alquiler','Mantenimiento') NOT NULL,
  `descripcion` varchar(255) NOT NULL,
  `periodo` char(7) NOT NULL,
  `monto` decimal(18,2) NOT NULL,
  `activo` tinyint(1) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Volcado de datos para la tabla `factura_detalle`
--

INSERT INTO `factura_detalle` (`id_detalles`, `factura_id`, `concepto`, `descripcion`, `periodo`, `monto`, `activo`) VALUES
(1, 1, 'Alquiler', 'Alquiler del período 2026-09', '2026-09', 18000.00, 1),
(2, 1, 'Mantenimiento', 'Mantenimiento del período 2026-09', '2026-09', 2500.00, 1),
(3, 2, 'Alquiler', 'Alquiler del período 2026-10', '2026-10', 18000.00, 1),
(4, 2, 'Mantenimiento', 'Mantenimiento del período 2026-10', '2026-10', 2500.00, 1),
(5, 3, 'Alquiler', 'Alquiler del período 2026-10', '2026-10', 25000.00, 1),
(6, 3, 'Mantenimiento', 'Mantenimiento del período 2026-10', '2026-10', 2500.00, 1),
(7, 4, 'Alquiler', 'Alquiler del período 2026-10', '2026-10', 20000.00, 1),
(8, 4, 'Mantenimiento', 'Mantenimiento del período 2026-10', '2026-10', 2500.00, 1),
(9, 5, 'Alquiler', 'Alquiler del período 2026-10', '2026-10', 14000.00, 1),
(10, 5, 'Mantenimiento', 'Mantenimiento del período 2026-10', '2026-10', 2000.00, 1),
(11, 6, 'Alquiler', 'Alquiler del período 2026-10', '2026-10', 16500.00, 1),
(12, 6, 'Mantenimiento', 'Mantenimiento del período 2026-10', '2026-10', 2500.00, 1),
(13, 7, 'Alquiler', 'PRUEBA TESTECF linea 1', '2026-10', 1000.00, 1),
(14, 7, 'Mantenimiento', 'PRUEBA TESTECF linea 2', '2026-10', 500.00, 1),
(15, 8, 'Alquiler', 'Alquiler del período 2026-11', '2026-11', 20000.00, 1),
(16, 8, 'Mantenimiento', 'Mantenimiento del período 2026-11', '2026-11', 2500.00, 1),
(17, 9, 'Alquiler', 'Alquiler del período 2026-12', '2026-12', 20000.00, 1),
(18, 9, 'Mantenimiento', 'Mantenimiento del período 2026-12', '2026-12', 2500.00, 1),
(19, 10, 'Alquiler', 'Alquiler del período 2027-01', '2027-01', 20000.00, 1),
(20, 10, 'Mantenimiento', 'Mantenimiento del período 2027-01', '2027-01', 2500.00, 1),
(21, 11, 'Alquiler', 'Alquiler del período 2026-01', '2026-01', 20000.00, 1),
(22, 11, 'Mantenimiento', 'Mantenimiento del período 2026-01', '2026-01', 2500.00, 1),
(23, 12, 'Alquiler', 'Alquiler del período 2026-09', '2026-09', 25000.00, 1),
(24, 12, 'Mantenimiento', 'Mantenimiento del período 2026-09', '2026-09', 2500.00, 1),
(25, 13, 'Alquiler', 'Alquiler del período 2026-09', '2026-09', 14000.00, 1),
(26, 13, 'Mantenimiento', 'Mantenimiento del período 2026-09', '2026-09', 2000.00, 1);

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `factura_ecf`
--

CREATE TABLE `factura_ecf` (
  `id_factura_ecf` int(11) NOT NULL,
  `factura_id` bigint(20) NOT NULL,
  `ecf_api_id` varchar(64) DEFAULT NULL,
  `encf` varchar(20) DEFAULT NULL,
  `fecha_emision_ecf` date DEFAULT NULL,
  `tipo_ecf` varchar(5) NOT NULL DEFAULT 'E32',
  `estado` varchar(60) NOT NULL,
  `codigo_seguridad` varchar(20) DEFAULT NULL,
  `dgii_url` text DEFAULT NULL,
  `error_mensaje` text DEFAULT NULL,
  `intentos` int(11) NOT NULL DEFAULT 0,
  `fecha_envio` datetime DEFAULT NULL,
  `fecha_registro` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Volcado de datos para la tabla `factura_ecf`
--

INSERT INTO `factura_ecf` (`id_factura_ecf`, `factura_id`, `ecf_api_id`, `encf`, `fecha_emision_ecf`, `tipo_ecf`, `estado`, `codigo_seguridad`, `dgii_url`, `error_mensaje`, `intentos`, `fecha_envio`, `fecha_registro`) VALUES
(1, 1, NULL, NULL, NULL, 'E32', 'PENDIENTE_CONFIGURACION', NULL, NULL, NULL, 0, NULL, '2026-10-17 14:20:51'),
(2, 2, NULL, NULL, NULL, 'E32', 'PENDIENTE_CONFIGURACION', NULL, NULL, NULL, 0, NULL, '2026-10-17 14:20:51'),
(3, 3, NULL, NULL, NULL, 'E32', 'PENDIENTE_CONFIGURACION', NULL, NULL, NULL, 0, NULL, '2026-10-17 14:20:51'),
(4, 4, 'df4e12e2-92e1-45d2-ba13-6241e6ec2f92', 'E320000001313', NULL, 'E32', 'Aceptado', 'Xmn2JW', 'https://fc.dgii.gov.do/testecf/consultatimbrefc?rncemisor=132907401&encf=E320000001313&montototal=26550.00&codigoseguridad=Xmn2JW', NULL, 1, '2026-10-17 11:48:26', '2026-10-17 14:20:51'),
(5, 5, NULL, NULL, NULL, 'E32', 'PENDIENTE_CONFIGURACION', NULL, NULL, NULL, 0, NULL, '2026-10-17 14:20:51'),
(7, 7, 'b2d2bcdb-8147-4470-abfc-5df9f996852a', 'E310000001312', NULL, 'E31', 'Aceptado Condicional', 'DENdlr', 'https://ecf.dgii.gov.do/testecf/consultatimbre?rncemisor=132907401&RncComprador=131234567&encf=E310000001312&fechaemision=19-10-2026&montototal=1770.00&fechafirma=19-10-2026%2021%3A02%3A55&codigoseguridad=DENdlr', NULL, 1, '2026-10-19 21:02:57', '2026-10-20 01:02:57'),
(8, 8, '8c82c6b4-fb2a-4b89-a4a8-bdf2cea0d0b5', 'E310000001313', '2026-10-19', 'E31', 'Aceptado Condicional', 'g6tsVr', 'https://ecf.dgii.gov.do/testecf/consultatimbre?rncemisor=132907401&RncComprador=130481121&encf=E310000001313&fechaemision=19-10-2026&montototal=26550.00&fechafirma=19-10-2026%2021%3A09%3A12&codigoseguridad=g6tsVr', NULL, 1, '2026-10-19 21:09:14', '2026-10-20 01:09:14'),
(9, 9, '1f92e5c1-686b-4c57-b4fc-3b7987bafc34', 'E310000001314', '2026-10-19', 'E31', 'Aceptado Condicional', 'OzNnUs', 'https://ecf.dgii.gov.do/testecf/consultatimbre?rncemisor=132907401&RncComprador=130481121&encf=E310000001314&fechaemision=19-10-2026&montototal=26550.00&fechafirma=19-10-2026%2021%3A14%3A11&codigoseguridad=OzNnUs', NULL, 1, '2026-10-19 21:14:13', '2026-10-20 01:14:13'),
(10, 10, 'bfc57cfe-5652-4f48-97e4-c23be8c58ca9', 'E310000001315', '2026-10-19', 'E31', 'Aceptado', 'FR0HEL', 'https://ecf.dgii.gov.do/testecf/consultatimbre?rncemisor=132907401&RncComprador=130481121&encf=E310000001315&fechaemision=19-10-2026&montototal=26550.00&fechafirma=19-10-2026%2021%3A17%3A44&codigoseguridad=FR0HEL', NULL, 1, '2026-10-19 21:17:46', '2026-10-20 01:17:46'),
(11, 11, 'be742c4b-e3f0-411f-92ab-5135d2fb58b3', 'E310000001319', '2026-10-19', 'E31', 'Aceptado', 'oh8Ant', 'https://ecf.dgii.gov.do/testecf/consultatimbre?rncemisor=132907401&RncComprador=131587577&encf=E310000001319&fechaemision=19-10-2026&montototal=26550.00&fechafirma=19-10-2026%2021%3A28%3A31&codigoseguridad=oh8Ant', NULL, 1, '2026-10-19 21:28:33', '2026-10-20 01:28:33'),
(12, 12, 'b7ef1e38-0afd-4a0f-9b95-72ee77b2ed54', 'E320000001351', '2026-10-19', 'E32', 'Aceptado', 'lS2mLb', 'https://fc.dgii.gov.do/testecf/consultatimbrefc?rncemisor=132907401&encf=E320000001351&montototal=32450.00&codigoseguridad=lS2mLb', NULL, 1, '2026-10-19 22:18:46', '2026-10-20 02:18:46'),
(13, 13, 'a5803c91-16a9-4769-a122-bee59fb1aed6', 'E310000001333', '2026-10-19', 'E31', 'Aceptado', 'bfsV8I', 'https://ecf.dgii.gov.do/testecf/consultatimbre?rncemisor=132907401&RncComprador=430340537&encf=E310000001333&fechaemision=19-10-2026&montototal=18880.00&fechafirma=19-10-2026%2022%3A35%3A09&codigoseguridad=bfsV8I', NULL, 1, '2026-10-19 22:35:11', '2026-10-20 02:35:11');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `factura_ecf_nota`
--

CREATE TABLE `factura_ecf_nota` (
  `id_factura_ecf_nota` int(11) NOT NULL,
  `factura_id` bigint(20) NOT NULL,
  `codigo_modificacion` tinyint(4) NOT NULL,
  `encf_modificado` varchar(20) NOT NULL,
  `fecha_encf_modificado` date NOT NULL,
  `monto_centavos` bigint(20) NOT NULL,
  `lineas_json` text NOT NULL,
  `usuario_id` int(11) DEFAULT NULL,
  `ecf_api_id` varchar(64) DEFAULT NULL,
  `encf` varchar(20) DEFAULT NULL,
  `estado` varchar(60) NOT NULL,
  `codigo_seguridad` varchar(20) DEFAULT NULL,
  `dgii_url` text DEFAULT NULL,
  `error_mensaje` text DEFAULT NULL,
  `intentos` int(11) NOT NULL DEFAULT 0,
  `fecha_envio` datetime DEFAULT NULL,
  `fecha_registro` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Volcado de datos para la tabla `factura_ecf_nota`
--

INSERT INTO `factura_ecf_nota` (`id_factura_ecf_nota`, `factura_id`, `codigo_modificacion`, `encf_modificado`, `fecha_encf_modificado`, `monto_centavos`, `lineas_json`, `usuario_id`, `ecf_api_id`, `encf`, `estado`, `codigo_seguridad`, `dgii_url`, `error_mensaje`, `intentos`, `fecha_envio`, `fecha_registro`) VALUES
(1, 7, 3, 'E310000001312', '2026-10-20', 1180, '[{\"descripcion\":\"Ajuste de prueba TESTECF\",\"centavos\":1000}]', NULL, '08f4eae2-870c-4026-85b3-40d8bc103daf', 'E340000001100', 'Rechazado', 'n/qtlO', 'https://ecf.dgii.gov.do/testecf/consultatimbre?rncemisor=132907401&RncComprador=131234567&encf=E340000001100&fechaemision=19-10-2026&montototal=11.80&fechafirma=19-10-2026%2021%3A03%3A04&codigoseguridad=n%2FqtlO', NULL, 1, '2026-10-19 21:03:06', '2026-10-20 01:03:03'),
(2, 7, 3, 'E310000001312', '2026-10-19', 1180, '[{\"descripcion\":\"Ajuste de prueba TESTECF\",\"centavos\":1000}]', NULL, '7f8cc6e9-eec8-4f03-924d-efc3d492bb16', 'E340000001101', 'Aceptado Condicional', 'TgRyUt', 'https://ecf.dgii.gov.do/testecf/consultatimbre?rncemisor=132907401&RncComprador=131234567&encf=E340000001101&fechaemision=19-10-2026&montototal=11.80&fechafirma=19-10-2026%2021%3A04%3A48&codigoseguridad=TgRyUt', NULL, 1, '2026-10-19 21:04:50', '2026-10-20 01:04:47'),
(3, 8, 3, 'E310000001313', '2026-10-20', 1180, '[{\"descripcion\":\"Ajuste de prueba E34\",\"centavos\":1000}]', 1, 'fbe85877-36da-4585-abda-62f48a2df4fd', 'E340000001102', 'Rechazado', 'ghukD9', 'https://ecf.dgii.gov.do/testecf/consultatimbre?rncemisor=132907401&RncComprador=130481121&encf=E340000001102&fechaemision=19-10-2026&montototal=11.80&fechafirma=19-10-2026%2021%3A09%3A41&codigoseguridad=ghukD9', NULL, 1, '2026-10-19 21:09:43', '2026-10-20 01:09:40'),
(4, 8, 3, 'E310000001313', '2026-10-19', 1180, '[{\"descripcion\":\"Ajuste de prueba E34\",\"centavos\":1000}]', 1, '1fa4fe14-ee3b-410a-8155-ab0d23b76a19', 'E340000001103', 'Aceptado', 'mw3vGm', 'https://ecf.dgii.gov.do/testecf/consultatimbre?rncemisor=132907401&RncComprador=130481121&encf=E340000001103&fechaemision=19-10-2026&montototal=11.80&fechafirma=19-10-2026%2021%3A12%3A50&codigoseguridad=mw3vGm', NULL, 1, '2026-10-19 21:12:52', '2026-10-20 01:12:49'),
(5, 11, 3, 'E310000001319', '2026-10-19', 2655000, '[{\"descripcion\":\"test monto prueba\",\"centavos\":2250000}]', 1, 'b96567d0-31e7-4b02-a105-d3719c0a704a', 'E340000001105', 'Aceptado', 'FS7K0l', 'https://ecf.dgii.gov.do/testecf/consultatimbre?rncemisor=132907401&RncComprador=131587577&encf=E340000001105&fechaemision=19-10-2026&montototal=26550.00&fechafirma=19-10-2026%2022%3A05%3A54&codigoseguridad=FS7K0l', NULL, 1, '2026-10-19 22:05:56', '2026-10-20 02:05:53'),
(6, 13, 3, 'E310000001333', '2026-10-19', 1888000, '[{\"descripcion\":\"test nulo\",\"centavos\":1600000}]', 1, 'dc79bf0a-7776-4b74-814f-3b5a2958772a', 'E340000001109', 'Aceptado', 'CD5dul', 'https://ecf.dgii.gov.do/testecf/consultatimbre?rncemisor=132907401&RncComprador=430340537&encf=E340000001109&fechaemision=19-10-2026&montototal=18880.00&fechafirma=19-10-2026%2022%3A36%3A41&codigoseguridad=CD5dul', NULL, 1, '2026-10-19 22:36:43', '2026-10-20 02:36:39');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `factura_pago`
--

CREATE TABLE `factura_pago` (
  `id_pagos` bigint(20) NOT NULL,
  `factura_id` bigint(20) NOT NULL,
  `fecha_pago` datetime NOT NULL DEFAULT current_timestamp(),
  `monto` decimal(18,2) NOT NULL,
  `metodo_pago` varchar(50) NOT NULL,
  `referencia` varchar(100) DEFAULT NULL,
  `observaciones` text DEFAULT NULL,
  `usuario_id` bigint(20) DEFAULT NULL
) ;

--
-- Volcado de datos para la tabla `factura_pago`
--

INSERT INTO `factura_pago` (`id_pagos`, `factura_id`, `fecha_pago`, `monto`, `metodo_pago`, `referencia`, `observaciones`, `usuario_id`) VALUES
(1, 1, '2026-09-03 10:15:00', 10000.00, 'Transferencia', 'TRF-90001', 'Primer abono', 1),
(2, 1, '2026-09-08 16:40:00', 10500.00, 'Efectivo', NULL, 'Saldo final', 1),
(3, 3, '2026-10-05 11:00:00', 10000.00, 'Tarjeta', 'TAR-45510', 'Abono', 1),
(4, 5, '2026-10-04 09:30:00', 8000.00, 'Cheque', 'CHQ-2231', 'Primer pago', 1),
(5, 5, '2026-10-06 15:20:00', 8000.00, 'Transferencia', 'TRF-90077', 'Pago final', 1),
(6, 10, '2026-10-19 22:07:06', 26550.00, 'Efectivo', NULL, NULL, 1);

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `mantenimiento`
--

CREATE TABLE `mantenimiento` (
  `id_mantenimientos` bigint(20) NOT NULL,
  `condominio_id` bigint(20) NOT NULL,
  `suplidor_id` bigint(20) DEFAULT NULL,
  `tipo` varchar(30) NOT NULL DEFAULT 'Puntual',
  `periodo` char(7) DEFAULT NULL,
  `descripcion` text NOT NULL,
  `fecha_programada` date DEFAULT NULL,
  `fecha_realizacion` date DEFAULT NULL,
  `costo` decimal(18,2) DEFAULT 0.00,
  `estado` varchar(30) DEFAULT 'Programado',
  `activo` tinyint(1) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Volcado de datos para la tabla `mantenimiento`
--

INSERT INTO `mantenimiento` (`id_mantenimientos`, `condominio_id`, `suplidor_id`, `tipo`, `periodo`, `descripcion`, `fecha_programada`, `fecha_realizacion`, `costo`, `estado`, `activo`) VALUES
(1, 1, 1, 'Mensual', '2026-09', 'Mantenimiento mensual de áreas comunes', '2026-09-05', '2026-09-05', 45000.00, 'Realizado', 1),
(2, 1, 1, 'Mensual', '2026-10', 'Mantenimiento mensual de áreas comunes', '2026-10-05', '2026-10-05', 45000.00, 'Realizado', 1),
(3, 2, 2, 'Mensual', '2026-10', 'Jardinería y limpieza de áreas verdes', '2026-10-25', NULL, 28000.00, 'Programado', 1),
(4, 3, 3, 'Puntual', NULL, 'Reparación de la bomba de agua', '2026-10-12', '2026-10-13', 18500.00, 'Realizado', 1),
(5, 4, 4, 'Puntual', NULL, 'Pintura de la fachada', '2026-11-05', NULL, 120000.00, 'Programado', 1),
(6, 5, 5, 'Mensual', '2026-10', 'Revisión de ascensores', '2026-10-15', '2026-10-15', 32000.00, 'Realizado', 1),
(9, 3, NULL, 'Mensual', '2026-10', 'Mantenimiento mensual de áreas comunes (2026-10)', '2026-10-01', NULL, 0.00, 'Programado', 1),
(10, 4, NULL, 'Mensual', '2026-10', 'Mantenimiento mensual de áreas comunes (2026-10)', '2026-10-01', NULL, 0.00, 'Programado', 1);

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `mantenimiento_solicitud`
--

CREATE TABLE `mantenimiento_solicitud` (
  `id_mantenimiento_solicitud` bigint(20) NOT NULL,
  `mantenimiento_id` bigint(20) NOT NULL,
  `solicitud_id` bigint(20) NOT NULL,
  `activo` tinyint(1) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Volcado de datos para la tabla `mantenimiento_solicitud`
--

INSERT INTO `mantenimiento_solicitud` (`id_mantenimiento_solicitud`, `mantenimiento_id`, `solicitud_id`, `activo`) VALUES
(1, 4, 1, 1),
(2, 4, 4, 1),
(3, 1, 2, 1),
(4, 2, 2, 1),
(5, 5, 5, 1);

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `mensaje_ia`
--

CREATE TABLE `mensaje_ia` (
  `id_mensajes` bigint(20) NOT NULL,
  `conversacion_id` bigint(20) NOT NULL,
  `tipo_emisor` enum('USUARIO','ASISTENTE') NOT NULL,
  `contenido` text NOT NULL,
  `fecha_envio` timestamp NOT NULL DEFAULT current_timestamp(),
  `activo` tinyint(1) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Volcado de datos para la tabla `mensaje_ia`
--

INSERT INTO `mensaje_ia` (`id_mensajes`, `conversacion_id`, `tipo_emisor`, `contenido`, `fecha_envio`, `activo`) VALUES
(1, 1, 'USUARIO', '¿Cuántos contratos vencen este mes?', '2026-10-16 13:00:10', 1),
(2, 1, 'ASISTENTE', 'Hay un contrato próximo a vencer: CONT-002.', '2026-10-16 13:00:14', 1),
(3, 2, 'USUARIO', '¿Qué propiedades tienen 3 habitaciones?', '2026-10-16 14:00:10', 1),
(4, 2, 'ASISTENTE', 'Tenemos el apartamento AB-102 con 3 habitaciones y 2 baños.', '2026-10-16 14:00:15', 1),
(5, 3, 'USUARIO', 'Busco un apartamento en venta.', '2026-10-16 15:00:10', 1),
(6, 3, 'ASISTENTE', 'Le recomiendo el penthouse P-305 o el apartamento AB-203.', '2026-10-16 15:00:16', 1),
(7, 4, 'USUARIO', '¿Cómo solicito una reparación?', '2026-10-17 12:30:10', 1),
(8, 4, 'ASISTENTE', 'Puede hacerlo desde el menú, en \"Solicitar una reparación\".', '2026-10-17 12:30:15', 1),
(9, 5, 'USUARIO', '¿Cuál es el precio del AB-102?', '2026-10-17 13:45:10', 1),
(10, 5, 'ASISTENTE', 'Se alquila por RD$ 30,000.00 al mes o se vende por RD$ 7,800,000.00.', '2026-10-17 13:45:15', 1);

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `notificacion`
--

CREATE TABLE `notificacion` (
  `id_notificaciones` bigint(20) NOT NULL,
  `usuario_id` bigint(20) NOT NULL,
  `titulo` varchar(150) NOT NULL,
  `mensaje` text NOT NULL,
  `tipo` varchar(50) DEFAULT NULL,
  `fecha_envio` timestamp NOT NULL DEFAULT current_timestamp(),
  `fecha_lectura` timestamp NULL DEFAULT NULL,
  `estado` varchar(30) DEFAULT 'Pendiente',
  `activo` tinyint(1) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Volcado de datos para la tabla `notificacion`
--

INSERT INTO `notificacion` (`id_notificaciones`, `usuario_id`, `titulo`, `mensaje`, `tipo`, `fecha_envio`, `fecha_lectura`, `estado`, `activo`) VALUES
(1, 1, 'Contrato por vencer', 'El contrato CONT-002 vence el 01/11/2026.', 'Sistema', '2026-10-17 14:20:51', NULL, 'Pendiente', 1),
(2, 1, 'Nueva solicitud', 'Hay una solicitud de alquiler pendiente de revisión.', 'Informativa', '2026-10-17 14:20:51', NULL, 'Pendiente', 1),
(3, 6, 'Pago recibido', 'Hemos registrado su abono de octubre.', 'Pago', '2026-10-17 14:20:51', NULL, 'Leida', 1),
(4, 7, 'Visita aprobada', 'Su visita del 22/10/2026 fue aprobada.', 'Informativa', '2026-10-17 14:20:51', NULL, 'Pendiente', 1),
(5, 9, 'Reparación en proceso', 'Su solicitud de reparación fue asignada a un suplidor.', 'reparacion', '2026-10-17 14:20:51', NULL, 'Pendiente', 1),
(6, 10, 'Factura disponible', 'Ya está disponible su factura de octubre.', 'Pago', '2026-10-17 14:20:51', NULL, 'Pendiente', 1);

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `ocupacion`
--

CREATE TABLE `ocupacion` (
  `id_ocupaciones` bigint(20) NOT NULL,
  `apartamento_id` bigint(20) NOT NULL,
  `persona_id` bigint(20) NOT NULL,
  `tipo_ocupacion` varchar(30) NOT NULL,
  `fecha_inicio` date NOT NULL,
  `fecha_fin` date DEFAULT NULL,
  `estado` varchar(30) DEFAULT 'Activa',
  `activo` tinyint(1) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Volcado de datos para la tabla `ocupacion`
--

INSERT INTO `ocupacion` (`id_ocupaciones`, `apartamento_id`, `persona_id`, `tipo_ocupacion`, `fecha_inicio`, `fecha_fin`, `estado`, `activo`) VALUES
(1, 1, 8, 'Inquilino', '2025-10-01', NULL, 'Activa', 1),
(2, 2, 9, 'Inquilino', '2025-11-01', NULL, 'Activa', 1),
(3, 3, 10, 'Propietario', '2026-09-15', NULL, 'Activa', 1),
(4, 4, 12, 'Inquilino', '2026-01-15', NULL, 'Activa', 1),
(5, 5, 13, 'Inquilino', '2026-03-01', NULL, 'Activa', 1),
(6, 6, 8, 'Inquilino', '2026-07-01', NULL, 'Activa', 1);

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `pago`
--

CREATE TABLE `pago` (
  `id_pagos` bigint(20) NOT NULL,
  `persona_id` bigint(20) NOT NULL,
  `apartamento_id` bigint(20) DEFAULT NULL,
  `contrato_id` bigint(20) DEFAULT NULL,
  `concepto_id` bigint(20) NOT NULL,
  `monto` decimal(18,2) NOT NULL,
  `moneda` varchar(3) DEFAULT 'DOP',
  `fecha_pago` timestamp NULL DEFAULT NULL,
  `estado` varchar(30) DEFAULT 'Pendiente',
  `descripcion` varchar(255) DEFAULT NULL,
  `fecha_registro` timestamp NOT NULL DEFAULT current_timestamp(),
  `activo` tinyint(1) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Volcado de datos para la tabla `pago`
--

INSERT INTO `pago` (`id_pagos`, `persona_id`, `apartamento_id`, `contrato_id`, `concepto_id`, `monto`, `moneda`, `fecha_pago`, `estado`, `descripcion`, `fecha_registro`, `activo`) VALUES
(1, 8, 1, 1, 1, 18000.00, 'DOP', '2026-09-02 14:00:00', 'Pagado', 'Alquiler septiembre 2026', '2026-10-17 14:20:51', 1),
(2, 8, 1, 1, 2, 2500.00, 'DOP', '2026-09-02 14:05:00', 'Pagado', 'Mantenimiento septiembre 2026', '2026-10-17 14:20:51', 1),
(3, 9, 2, 2, 1, 10000.00, 'DOP', '2026-10-03 16:00:00', 'Pagado', 'Abono alquiler octubre 2026', '2026-10-17 14:20:51', 1),
(4, 13, 5, 5, 1, 14000.00, 'DOP', '2026-10-04 13:30:00', 'Pagado', 'Alquiler octubre 2026', '2026-10-17 14:20:51', 1),
(5, 12, 4, 4, 4, 40000.00, 'DOP', '2026-01-14 18:00:00', 'Pagado', 'Depósito de garantía CONT-004', '2026-10-17 14:20:51', 1);

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `persona`
--

CREATE TABLE `persona` (
  `id_personas` bigint(20) NOT NULL,
  `nombres` varchar(100) NOT NULL,
  `apellidos` varchar(100) NOT NULL,
  `cedula` varchar(20) DEFAULT NULL,
  `correo` varchar(120) DEFAULT NULL,
  `telefono` varchar(40) DEFAULT NULL,
  `direccion` varchar(255) DEFAULT NULL,
  `fecha_registro` timestamp NOT NULL DEFAULT current_timestamp(),
  `activo` tinyint(1) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Volcado de datos para la tabla `persona`
--

INSERT INTO `persona` (`id_personas`, `nombres`, `apellidos`, `cedula`, `correo`, `telefono`, `direccion`, `fecha_registro`, `activo`) VALUES
(1, 'Ana', 'Martinez', '00100000001', 'ana@example.com', '8095550101', 'Santiago', '2026-10-14 16:50:56', 1),
(2, 'Carlos', 'Perez', '00100000002', 'carlos@example.com', '8095550102', 'Santo Domingo', '2026-10-14 16:50:56', 1),
(3, 'Maria', 'Lopez', '00100000003', 'maria@example.com', '8095550103', 'La Vega', '2026-10-14 16:50:56', 1),
(7, 'Ericka', 'Cabrera Diaz', '402-1023221-8', 'ecabreradiaz16@gmail.com', '000-000-0000', 'Pontezuela', '2026-10-15 01:22:36', 1),
(8, 'Ericka', 'Cabrera Diaz', '000-0000000-0', 'erikcacd25@gmail.com', '000-000-0000', 'Pontezuela', '2026-10-15 01:31:06', 1),
(9, 'guaca', 'guaca', '402-0905870-0', 'guac@gmail.com', '000-000-0000', 'Pontezuela', '2026-10-15 02:33:42', 1),
(10, 'sahid', 'tejada', '402-1023221-9', 'sah@gmail.com', '000-000-0000', 'Pontezuela', '2026-10-15 03:25:54', 1),
(11, 'aldenis', 'cabrera', '401-1023221-9', 'ald@gmail.com', '000-000-0000', 'Pontezuela', '2026-10-17 09:06:59', 1),
(12, 'diose', 'cabrera', '400-1023221-8', 'diose@gmail.com', '000-000-0000', 'Pontezuela', '2026-10-17 09:11:44', 1),
(13, 'jary', 'noel', '301-1023221-8', 'jary@gmail.com', '000-000-0000', 'Pontezuela', '2026-10-17 12:58:39', 1),
(14, 'prueba', 'prueba', '000-1023221-8', 'prueba@gmail.com', '000-000-0000', 'santiago', '2026-10-17 14:43:23', 1),
(15, 'Ivan', 'Prueba', '402-0003221-8', 'ivan@gmail.com', '000-000-0000', 'Pontezuela', '2026-10-17 15:39:44', 1);

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `persona_rol`
--

CREATE TABLE `persona_rol` (
  `id_persona_rol` bigint(20) NOT NULL,
  `persona_id` bigint(20) NOT NULL,
  `tipo_rol` varchar(30) NOT NULL,
  `fecha_registro` date NOT NULL,
  `activo` tinyint(1) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Volcado de datos para la tabla `persona_rol`
--

INSERT INTO `persona_rol` (`id_persona_rol`, `persona_id`, `tipo_rol`, `fecha_registro`, `activo`) VALUES
(1, 1, 'Administrador', '2026-01-10', 1),
(2, 2, 'Empleado', '2026-02-15', 1),
(3, 3, 'Residente', '2026-03-20', 1),
(4, 7, 'Cliente', '2026-10-14', 1),
(5, 8, 'Cliente', '2026-10-14', 1),
(6, 9, 'Cliente', '2026-10-14', 1),
(7, 10, 'Cliente', '2026-10-14', 1),
(8, 11, 'Cliente', '2026-10-17', 1),
(9, 12, 'Cliente', '2026-10-17', 1),
(10, 13, 'Cliente', '2026-10-17', 1),
(11, 14, 'Cliente', '2026-10-17', 1),
(12, 15, 'Cliente', '2026-10-17', 1);

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `propiedad`
--

CREATE TABLE `propiedad` (
  `id_propiedades` bigint(20) NOT NULL,
  `apartamento_id` bigint(20) NOT NULL,
  `tipo_operacion` enum('VENTA','ALQUILER') NOT NULL,
  `precio` decimal(18,2) NOT NULL,
  `descripcion` text DEFAULT NULL,
  `fecha_publicacion` date DEFAULT NULL,
  `estado` varchar(30) DEFAULT 'Disponible',
  `activo` tinyint(1) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Volcado de datos para la tabla `propiedad`
--

INSERT INTO `propiedad` (`id_propiedades`, `apartamento_id`, `tipo_operacion`, `precio`, `descripcion`, `fecha_publicacion`, `estado`, `activo`) VALUES
(1, 7, 'ALQUILER', 30000.00, 'Apartamento familiar junto al parque', '2026-09-20', 'Disponible', 1),
(2, 7, 'VENTA', 7800000.00, 'Apartamento familiar junto al parque', '2026-09-20', 'Disponible', 1),
(3, 8, 'ALQUILER', 22000.00, 'Apartamento remodelado', '2026-10-01', 'Disponible', 1),
(4, 8, 'VENTA', 5200000.00, 'Apartamento remodelado', '2026-10-01', 'Disponible', 1),
(5, 9, 'VENTA', 9500000.00, 'Penthouse con terraza y jacuzzi', '2026-10-05', 'Disponible', 1);

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `renovacion_contrato`
--

CREATE TABLE `renovacion_contrato` (
  `id_renovaciones` bigint(20) NOT NULL,
  `contrato_id` bigint(20) NOT NULL,
  `fecha_renovacion` date NOT NULL,
  `nueva_fecha_fin` date DEFAULT NULL,
  `nuevo_monto` decimal(18,2) DEFAULT NULL,
  `observaciones` text DEFAULT NULL,
  `activo` tinyint(1) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Volcado de datos para la tabla `renovacion_contrato`
--

INSERT INTO `renovacion_contrato` (`id_renovaciones`, `contrato_id`, `fecha_renovacion`, `nueva_fecha_fin`, `nuevo_monto`, `observaciones`, `activo`) VALUES
(1, 1, '2026-04-10', '2026-10-01', 18000.00, 'Extensión de plazo sin cambio de monto', 1),
(2, 2, '2026-05-02', '2026-11-01', 25000.00, 'Renovación anual', 1),
(3, 4, '2026-06-20', '2027-01-15', 20000.00, 'Ajuste de plazo', 1),
(4, 5, '2026-08-10', '2027-03-01', 14000.00, 'Renovación por buen historial de pagos', 1),
(5, 6, '2026-09-01', '2026-12-01', 16500.00, 'Extensión acordada con el inquilino', 1);

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `role`
--

CREATE TABLE `role` (
  `id_roles` bigint(20) NOT NULL,
  `nombre` varchar(60) NOT NULL,
  `descripcion` varchar(255) DEFAULT NULL,
  `activo` tinyint(1) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Volcado de datos para la tabla `role`
--

INSERT INTO `role` (`id_roles`, `nombre`, `descripcion`, `activo`) VALUES
(1, 'Administrador', 'Administra el sistema completo', 1),
(2, 'Empleado', 'Gestiona condominios y mantenimientos', 1),
(3, 'Residente', 'Consulta sus propiedades y pagos', 1),
(4, 'Cliente', 'Usuario registrado que necesita una suscripción para acceder', 1);

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `solicitud_mantenimiento`
--

CREATE TABLE `solicitud_mantenimiento` (
  `id_solicitudes` bigint(20) NOT NULL,
  `apartamento_id` bigint(20) NOT NULL,
  `persona_id` bigint(20) NOT NULL,
  `suplidor_id` bigint(20) DEFAULT NULL,
  `descripcion` text NOT NULL,
  `ubicacion` varchar(150) DEFAULT NULL,
  `prioridad` varchar(30) DEFAULT 'Media',
  `estado` varchar(30) DEFAULT 'Pendiente',
  `fecha_solicitud` timestamp NOT NULL DEFAULT current_timestamp(),
  `activo` tinyint(1) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Volcado de datos para la tabla `solicitud_mantenimiento`
--

INSERT INTO `solicitud_mantenimiento` (`id_solicitudes`, `apartamento_id`, `persona_id`, `suplidor_id`, `descripcion`, `ubicacion`, `prioridad`, `estado`, `fecha_solicitud`, `activo`) VALUES
(1, 1, 8, 3, 'Fuga de agua en el lavamanos', 'Baño', 'Alta', 'Pendiente', '2026-10-10 12:30:00', 1),
(2, 2, 9, 1, 'El aire acondicionado no enfría', 'Sala', 'Media', 'Pendiente', '2026-10-08 18:10:00', 1),
(3, 4, 12, NULL, 'La puerta del balcón no cierra bien', 'Balcón', 'Baja', 'Pendiente', '2026-10-14 21:00:00', 1),
(4, 5, 13, 3, 'Tubería tapada', 'Cocina', 'Alta', 'Completada', '2026-10-02 13:20:00', 1),
(5, 6, 8, 4, 'Humedad en la pared', 'Habitación', 'Media', 'Completada', '2026-09-28 15:45:00', 1);

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `solicitud_propiedad`
--

CREATE TABLE `solicitud_propiedad` (
  `id_solicitudes` bigint(20) NOT NULL,
  `persona_id` bigint(20) NOT NULL,
  `apartamento_id` bigint(20) NOT NULL,
  `tipo_operacion` enum('Alquiler','Venta') NOT NULL,
  `observaciones` text DEFAULT NULL,
  `estado` enum('Pendiente','En revision','Aprobada','Rechazada','Cancelada') DEFAULT 'Pendiente',
  `fecha_solicitud` timestamp NOT NULL DEFAULT current_timestamp(),
  `activo` tinyint(1) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Volcado de datos para la tabla `solicitud_propiedad`
--

INSERT INTO `solicitud_propiedad` (`id_solicitudes`, `persona_id`, `apartamento_id`, `tipo_operacion`, `observaciones`, `estado`, `fecha_solicitud`, `activo`) VALUES
(1, 8, 1, 'Alquiler', 'Solicitud de alquiler aprobada', 'Aprobada', '2025-09-20 14:00:00', 1),
(2, 9, 2, 'Alquiler', 'Solicitud de alquiler aprobada', 'Aprobada', '2025-10-18 15:30:00', 1),
(3, 10, 3, 'Venta', 'Compra al contado', 'Aprobada', '2026-09-01 13:15:00', 1),
(4, 12, 4, 'Alquiler', 'Solicitud de alquiler aprobada', 'Aprobada', '2026-01-05 18:00:00', 1),
(5, 13, 5, 'Alquiler', 'Solicitud de alquiler aprobada', 'Aprobada', '2026-02-15 20:20:00', 1),
(6, 8, 6, 'Alquiler', 'Solicitud de alquiler aprobada', 'Aprobada', '2026-06-10 12:45:00', 1),
(7, 9, 7, 'Alquiler', 'Interesada en mudarse en noviembre', 'Pendiente', '2026-10-14 14:10:00', 1),
(8, 10, 8, 'Venta', 'Solicita información de financiamiento', 'En revision', '2026-10-15 17:40:00', 1),
(9, 15, 8, 'Venta', 'ASAAP', 'Aprobada', '2026-10-17 15:46:05', 1);

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `suplidor`
--

CREATE TABLE `suplidor` (
  `id_suplidores` bigint(20) NOT NULL,
  `nombre` varchar(150) NOT NULL,
  `rnc` varchar(30) DEFAULT NULL,
  `telefono` varchar(40) DEFAULT NULL,
  `correo` varchar(120) DEFAULT NULL,
  `direccion` varchar(255) DEFAULT NULL,
  `activo` tinyint(1) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Volcado de datos para la tabla `suplidor`
--

INSERT INTO `suplidor` (`id_suplidores`, `nombre`, `rnc`, `telefono`, `correo`, `direccion`, `activo`) VALUES
(1, 'Servicios Técnicos del Caribe', '131000011', '809-555-0111', 'contacto@stcaribe.example.com', 'Av. 27 de Febrero #200, Santo Domingo', 1),
(2, 'Jardines Verdes SRL', '131000022', '809-555-0122', 'info@jardinesverdes.example.com', 'Calle Duarte #15, Santiago', 1),
(3, 'Plomería Express', '131000033', '809-555-0133', 'servicio@plomeriaexpress.example.com', 'Av. Independencia #310, Santo Domingo', 1),
(4, 'Pinturas y Acabados RD', '131000044', '809-555-0144', 'ventas@pinturasrd.example.com', 'Calle El Sol #77, La Vega', 1),
(5, 'Ascensores Dominicanos', '131000055', '809-555-0155', 'soporte@ascensoresrd.example.com', 'Av. Lincoln #90, Santo Domingo', 1);

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `suscripcion`
--

CREATE TABLE `suscripcion` (
  `id_suscripcion` bigint(20) NOT NULL,
  `usuario_id` bigint(20) NOT NULL,
  `paypal_subscription_id` varchar(100) DEFAULT NULL,
  `paypal_plan_id` varchar(100) DEFAULT NULL,
  `monto` decimal(10,2) NOT NULL DEFAULT 10.00,
  `moneda` varchar(3) NOT NULL DEFAULT 'USD',
  `estado` varchar(30) NOT NULL DEFAULT 'Pendiente',
  `fecha_inicio` datetime DEFAULT NULL,
  `fecha_proximo_pago` datetime DEFAULT NULL,
  `fecha_actualizacion` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Volcado de datos para la tabla `suscripcion`
--

INSERT INTO `suscripcion` (`id_suscripcion`, `usuario_id`, `paypal_subscription_id`, `paypal_plan_id`, `monto`, `moneda`, `estado`, `fecha_inicio`, `fecha_proximo_pago`, `fecha_actualizacion`) VALUES
(1, 6, 'I-DEMO0001', 'P-2GW66466MT116720LNLIDS2A', 10.00, 'USD', 'Activo', '2026-10-14 10:00:00', '2026-11-14 10:00:00', '2026-10-17 14:20:51'),
(2, 7, 'I-DEMO0002', 'P-2GW66466MT116720LNLIDS2A', 10.00, 'USD', 'Activo', '2026-10-14 11:00:00', '2026-11-14 11:00:00', '2026-10-17 14:20:51'),
(3, 9, 'I-DEMO0003', 'P-2GW66466MT116720LNLIDS2A', 10.00, 'USD', 'Activo', '2026-10-17 09:00:00', '2026-11-17 09:00:00', '2026-10-17 14:20:51'),
(4, 10, 'I-DEMO0004', 'P-2GW66466MT116720LNLIDS2A', 10.00, 'USD', 'Activo', '2026-10-17 09:30:00', '2026-11-17 09:30:00', '2026-10-17 14:20:51'),
(5, 5, NULL, 'P-2GW66466MT116720LNLIDS2A', 10.00, 'USD', 'Pendiente', NULL, NULL, '2026-10-17 14:20:51'),
(6, 11, 'I-HNVYF3E1LPM1', 'P-2GW66466MT116720LNLIDS2A', 10.00, 'USD', 'Activo', '2026-10-17 14:43:30', '2026-11-17 10:00:00', '2026-10-17 14:44:02'),
(7, 12, 'I-A969B8VY0T4K', 'P-2GW66466MT116720LNLIDS2A', 10.00, 'USD', 'Activo', '2026-10-17 15:39:52', '2026-11-17 10:00:00', '2026-10-17 15:40:43');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `transaccion_paypal`
--

CREATE TABLE `transaccion_paypal` (
  `id_transacciones` bigint(20) NOT NULL,
  `pago_id` bigint(20) NOT NULL,
  `codigo_transaccion` varchar(100) NOT NULL,
  `estado` varchar(30) NOT NULL,
  `monto` decimal(18,2) NOT NULL,
  `moneda` varchar(3) NOT NULL,
  `fecha_transaccion` timestamp NULL DEFAULT NULL,
  `respuesta_paypal` text DEFAULT NULL,
  `activo` tinyint(1) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Volcado de datos para la tabla `transaccion_paypal`
--

INSERT INTO `transaccion_paypal` (`id_transacciones`, `pago_id`, `codigo_transaccion`, `estado`, `monto`, `moneda`, `fecha_transaccion`, `respuesta_paypal`, `activo`) VALUES
(1, 1, 'PAYPAL-DEMO-001', 'COMPLETADA', 18000.00, 'DOP', '2026-09-02 14:00:00', '{\"demo\":true}', 1),
(2, 2, 'PAYPAL-DEMO-002', 'COMPLETADA', 2500.00, 'DOP', '2026-09-02 14:05:00', '{\"demo\":true}', 1),
(3, 3, 'PAYPAL-DEMO-003', 'COMPLETADA', 10000.00, 'DOP', '2026-10-03 16:00:00', '{\"demo\":true}', 1),
(4, 4, 'PAYPAL-DEMO-004', 'COMPLETADA', 14000.00, 'DOP', '2026-10-04 13:30:00', '{\"demo\":true}', 1),
(5, 5, 'PAYPAL-DEMO-005', 'COMPLETADA', 40000.00, 'DOP', '2026-01-14 18:00:00', '{\"demo\":true}', 1);

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `usuario`
--

CREATE TABLE `usuario` (
  `id_usuarios` bigint(20) NOT NULL,
  `nombre` varchar(100) NOT NULL,
  `usuario` varchar(50) NOT NULL,
  `clave` varchar(255) NOT NULL,
  `id_rol` bigint(20) NOT NULL,
  `persona_id` bigint(20) NOT NULL,
  `activo` tinyint(1) DEFAULT 1,
  `fecha_registro` timestamp NOT NULL DEFAULT current_timestamp(),
  `estado_cuenta` enum('Pendiente de pago','Activo','Suspendido') NOT NULL DEFAULT 'Pendiente de pago'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Volcado de datos para la tabla `usuario`
--

INSERT INTO `usuario` (`id_usuarios`, `nombre`, `usuario`, `clave`, `id_rol`, `persona_id`, `activo`, `fecha_registro`, `estado_cuenta`) VALUES
(1, 'Ericka Cabrera', 'admin', '1234', 1, 1, 1, '2026-10-14 16:50:56', 'Pendiente de pago'),
(2, 'Carlos Perez', 'carlos_emp', 'DEMO_REEMPLAZAR_HASH_2', 2, 2, 1, '2026-10-14 16:50:56', 'Pendiente de pago'),
(3, 'Maria Lopez', 'maria_res', 'DEMO_REEMPLAZAR_HASH_3', 3, 3, 1, '2026-10-14 16:50:56', 'Pendiente de pago'),
(4, 'Ericka Cabrera Diaz', 'eri', '$2y$10$P8RBYr9WUfh6eU02Cwia9uArwnn.YeoHetozcd27sWmJmVGdfFfz.', 4, 7, 0, '2026-10-15 01:22:36', 'Pendiente de pago'),
(5, 'Ericka Cabrera Diaz', 'luz', '123', 4, 8, 1, '2026-10-15 01:31:06', 'Pendiente de pago'),
(6, 'guaca guaca', 'gua', '1234', 4, 9, 1, '2026-10-15 02:33:42', 'Activo'),
(7, 'sahid tejada', 'sah', '1234', 4, 10, 1, '2026-10-15 03:25:54', 'Activo'),
(8, 'aldenis cabrera', 'alde', '$2y$10$9HTOJcHDX.RABBFWoP.We.uMgJQXqUmQV.yfLiSSH3Gg9Ij2hNSbG', 4, 11, 0, '2026-10-17 09:06:59', 'Pendiente de pago'),
(9, 'diose cabrera', 'diose', '$2y$10$eKwV8aBNu0mzbaoifl46mejTAyn8MXBNFIWZsL6.XJOh3THRQEejG', 4, 12, 1, '2026-10-17 09:11:44', 'Activo'),
(10, 'jary noel', 'jn0', '$2y$10$lQeJq5xtqxiU2DyEWO5kvekNsyQ7DUR8T8K0gMNd2HQgvKLXgVgvm', 4, 13, 1, '2026-10-17 12:58:39', 'Activo'),
(11, 'prueba prueba', 'prueba', '$2y$10$KY86t/Z/WcURpQPre26zYeTwDOWZaFElpN/fGI9M502xSQQHc8Yfa', 4, 14, 1, '2026-10-17 14:43:23', 'Activo'),
(12, 'Ivan Prueba', 'ivan1', '$2y$10$etmlnJL7.MDJm3JmcRdD0OXK7bZ1v2zP0AAoSYp9lQ1ZWlqI1OG1K', 4, 15, 1, '2026-10-17 15:39:44', 'Activo');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `viora_factura`
--

CREATE TABLE `viora_factura` (
  `id_factura` bigint(20) NOT NULL,
  `contrato_id` bigint(20) NOT NULL,
  `persona_id` bigint(20) NOT NULL,
  `periodo` char(7) NOT NULL,
  `renta` decimal(18,2) NOT NULL DEFAULT 0.00,
  `mantenimiento` decimal(18,2) NOT NULL DEFAULT 0.00,
  `total` decimal(18,2) NOT NULL,
  `estado` varchar(30) NOT NULL DEFAULT 'Borrador',
  `fecha_creacion` timestamp NOT NULL DEFAULT current_timestamp(),
  `estado_ecf` varchar(30) NOT NULL DEFAULT 'No enviado',
  `ambiente_ecf` varchar(20) DEFAULT NULL,
  `encf` varchar(30) DEFAULT NULL,
  `ecf_uuid` varchar(150) DEFAULT NULL,
  `track_id` varchar(150) DEFAULT NULL,
  `respuesta_dgii` text DEFAULT NULL,
  `fecha_envio_dgii` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Volcado de datos para la tabla `viora_factura`
--

INSERT INTO `viora_factura` (`id_factura`, `contrato_id`, `persona_id`, `periodo`, `renta`, `mantenimiento`, `total`, `estado`, `fecha_creacion`, `estado_ecf`, `ambiente_ecf`, `encf`, `ecf_uuid`, `track_id`, `respuesta_dgii`, `fecha_envio_dgii`) VALUES
(1, 1, 8, '2026-10', 18000.00, 2500.00, 20500.00, 'Borrador', '2026-10-17 14:20:51', 'No enviado', NULL, NULL, NULL, NULL, NULL, NULL),
(2, 2, 9, '2026-10', 25000.00, 2500.00, 27500.00, 'Pendiente', '2026-10-17 14:20:51', 'No enviado', NULL, NULL, NULL, NULL, NULL, NULL),
(3, 4, 12, '2026-10', 20000.00, 2500.00, 22500.00, 'Pagada', '2026-10-17 14:20:51', 'No enviado', NULL, NULL, NULL, NULL, NULL, NULL),
(4, 5, 13, '2026-10', 14000.00, 2000.00, 16000.00, 'Pagada', '2026-10-17 14:20:51', 'No enviado', NULL, NULL, NULL, NULL, NULL, NULL),
(5, 6, 8, '2026-10', 16500.00, 2500.00, 19000.00, 'Borrador', '2026-10-17 14:20:51', 'No enviado', NULL, NULL, NULL, NULL, NULL, NULL);

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `viora_factura_detalle`
--

CREATE TABLE `viora_factura_detalle` (
  `id_detalle` bigint(20) NOT NULL,
  `factura_id` bigint(20) NOT NULL,
  `tipo_item` varchar(30) NOT NULL,
  `descripcion` varchar(150) NOT NULL,
  `cantidad` decimal(12,2) NOT NULL DEFAULT 1.00,
  `precio_unitario` decimal(18,2) NOT NULL,
  `subtotal` decimal(18,2) NOT NULL,
  `porcentaje_itbis` decimal(5,2) DEFAULT NULL,
  `monto_itbis` decimal(18,2) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Volcado de datos para la tabla `viora_factura_detalle`
--

INSERT INTO `viora_factura_detalle` (`id_detalle`, `factura_id`, `tipo_item`, `descripcion`, `cantidad`, `precio_unitario`, `subtotal`, `porcentaje_itbis`, `monto_itbis`) VALUES
(1, 1, 'Alquiler', 'Alquiler del período 2026-10', 1.00, 18000.00, 18000.00, NULL, NULL),
(2, 1, 'Mantenimiento', 'Mantenimiento del período 2026-10', 1.00, 2500.00, 2500.00, NULL, NULL),
(3, 2, 'Alquiler', 'Alquiler del período 2026-10', 1.00, 25000.00, 25000.00, NULL, NULL),
(4, 2, 'Mantenimiento', 'Mantenimiento del período 2026-10', 1.00, 2500.00, 2500.00, NULL, NULL),
(5, 3, 'Alquiler', 'Alquiler del período 2026-10', 1.00, 20000.00, 20000.00, NULL, NULL),
(6, 3, 'Mantenimiento', 'Mantenimiento del período 2026-10', 1.00, 2500.00, 2500.00, NULL, NULL),
(7, 4, 'Alquiler', 'Alquiler del período 2026-10', 1.00, 14000.00, 14000.00, NULL, NULL),
(8, 4, 'Mantenimiento', 'Mantenimiento del período 2026-10', 1.00, 2000.00, 2000.00, NULL, NULL),
(9, 5, 'Alquiler', 'Alquiler del período 2026-10', 1.00, 16500.00, 16500.00, NULL, NULL),
(10, 5, 'Mantenimiento', 'Mantenimiento del período 2026-10', 1.00, 2500.00, 2500.00, NULL, NULL);

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `viora_pago`
--

CREATE TABLE `viora_pago` (
  `id_pago` bigint(20) NOT NULL,
  `factura_id` bigint(20) NOT NULL,
  `persona_id` bigint(20) NOT NULL,
  `monto` decimal(12,2) NOT NULL,
  `metodo_pago` varchar(30) NOT NULL,
  `referencia` varchar(150) DEFAULT NULL,
  `estado` varchar(30) NOT NULL DEFAULT 'Pendiente',
  `fecha_pago` datetime DEFAULT NULL,
  `fecha_creacion` timestamp NOT NULL DEFAULT current_timestamp()
) ;

--
-- Volcado de datos para la tabla `viora_pago`
--

INSERT INTO `viora_pago` (`id_pago`, `factura_id`, `persona_id`, `monto`, `metodo_pago`, `referencia`, `estado`, `fecha_pago`, `fecha_creacion`) VALUES
(1, 2, 9, 10000.00, 'Transferencia', 'VP-0001', 'Pagado', '2026-10-05 10:00:00', '2026-10-17 14:20:51'),
(2, 3, 12, 12000.00, 'Tarjeta', 'VP-0002', 'Pagado', '2026-10-06 11:30:00', '2026-10-17 14:20:51'),
(3, 3, 12, 10500.00, 'Efectivo', 'VP-0003', 'Pagado', '2026-10-08 09:10:00', '2026-10-17 14:20:51'),
(4, 4, 13, 8000.00, 'Cheque', 'VP-0004', 'Pagado', '2026-10-04 15:00:00', '2026-10-17 14:20:51'),
(5, 4, 13, 8000.00, 'Transferencia', 'VP-0005', 'Pagado', '2026-10-06 16:45:00', '2026-10-17 14:20:51');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `visita_propiedad`
--

CREATE TABLE `visita_propiedad` (
  `id_visita` bigint(20) NOT NULL,
  `apartamento_id` bigint(20) NOT NULL,
  `persona_id` bigint(20) NOT NULL,
  `fecha_visita` datetime NOT NULL,
  `observaciones` text DEFAULT NULL,
  `estado` enum('Pendiente','Aprobada','Rechazada','Cancelada') NOT NULL DEFAULT 'Pendiente',
  `fecha_solicitud` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Volcado de datos para la tabla `visita_propiedad`
--

INSERT INTO `visita_propiedad` (`id_visita`, `apartamento_id`, `persona_id`, `fecha_visita`, `observaciones`, `estado`, `fecha_solicitud`) VALUES
(1, 7, 9, '2026-10-20 10:00:00', 'Quiere conocer el área de parqueo', 'Pendiente', '2026-10-15 13:00:00'),
(2, 8, 10, '2026-10-22 15:00:00', 'Visita con su familia', 'Aprobada', '2026-10-15 14:30:00'),
(3, 9, 12, '2026-10-25 11:30:00', NULL, 'Aprobada', '2026-10-16 16:00:00'),
(4, 7, 13, '2026-10-12 09:00:00', 'Canceló por viaje', 'Cancelada', '2026-10-08 12:00:00'),
(5, 8, 8, '2026-10-28 16:00:00', 'Horario fuera de disponibilidad', 'Rechazada', '2026-10-16 21:20:00'),
(7, 7, 9, '2026-10-20 15:00:00', '', 'Pendiente', '2026-10-20 02:59:49'),
(8, 7, 9, '2026-10-21 15:00:00', '', 'Pendiente', '2026-10-20 21:48:17');

--
-- Índices para tablas volcadas
--

--
-- Indices de la tabla `apartamento`
--
ALTER TABLE `apartamento`
  ADD PRIMARY KEY (`id_apartamentos`),
  ADD KEY `edificio_id` (`edificio_id`);

--
-- Indices de la tabla `comprobante_fiscal`
--
ALTER TABLE `comprobante_fiscal`
  ADD PRIMARY KEY (`id_comprobantes`),
  ADD UNIQUE KEY `numero_comprobante` (`numero_comprobante`),
  ADD KEY `pago_id` (`pago_id`);

--
-- Indices de la tabla `concepto_pago`
--
ALTER TABLE `concepto_pago`
  ADD PRIMARY KEY (`id_conceptos_pago`),
  ADD UNIQUE KEY `nombre` (`nombre`);

--
-- Indices de la tabla `condominio`
--
ALTER TABLE `condominio`
  ADD PRIMARY KEY (`id_condominios`);

--
-- Indices de la tabla `contrato`
--
ALTER TABLE `contrato`
  ADD PRIMARY KEY (`id_contratos`),
  ADD UNIQUE KEY `numero_contrato` (`numero_contrato`),
  ADD UNIQUE KEY `uq_contrato_solicitud` (`solicitud_id`),
  ADD KEY `apartamento_id` (`apartamento_id`),
  ADD KEY `propiedad_id` (`propiedad_id`),
  ADD KEY `fk_contrato_persona` (`persona_id`);

--
-- Indices de la tabla `contrato_detalle_persona`
--
ALTER TABLE `contrato_detalle_persona`
  ADD PRIMARY KEY (`id_contrato_persona`),
  ADD KEY `contrato_id` (`contrato_id`),
  ADD KEY `persona_id` (`persona_id`);

--
-- Indices de la tabla `conversacion_ia`
--
ALTER TABLE `conversacion_ia`
  ADD PRIMARY KEY (`id_conversaciones`),
  ADD KEY `usuario_id` (`usuario_id`);

--
-- Indices de la tabla `documento`
--
ALTER TABLE `documento`
  ADD PRIMARY KEY (`id_documentos`),
  ADD KEY `contrato_id` (`contrato_id`),
  ADD KEY `persona_id` (`persona_id`);

--
-- Indices de la tabla `edificio`
--
ALTER TABLE `edificio`
  ADD PRIMARY KEY (`id_edificios`),
  ADD KEY `condominio_id` (`condominio_id`);

--
-- Indices de la tabla `factura`
--
ALTER TABLE `factura`
  ADD PRIMARY KEY (`id_facturas`),
  ADD UNIQUE KEY `numero_documento` (`numero_documento`),
  ADD KEY `idx_factura_cliente` (`persona_id`),
  ADD KEY `idx_factura_periodo` (`contrato_id`,`periodo`);

--
-- Indices de la tabla `factura_detalle`
--
ALTER TABLE `factura_detalle`
  ADD PRIMARY KEY (`id_detalles`),
  ADD UNIQUE KEY `uq_factura_concepto_periodo` (`factura_id`,`concepto`,`periodo`);

--
-- Indices de la tabla `factura_ecf`
--
ALTER TABLE `factura_ecf`
  ADD PRIMARY KEY (`id_factura_ecf`),
  ADD UNIQUE KEY `uq_factura_ecf_factura` (`factura_id`);

--
-- Indices de la tabla `factura_ecf_nota`
--
ALTER TABLE `factura_ecf_nota`
  ADD PRIMARY KEY (`id_factura_ecf_nota`),
  ADD KEY `ix_factura_ecf_nota_factura` (`factura_id`);

--
-- Indices de la tabla `factura_pago`
--
ALTER TABLE `factura_pago`
  ADD PRIMARY KEY (`id_pagos`),
  ADD KEY `factura_id` (`factura_id`);

--
-- Indices de la tabla `mantenimiento`
--
ALTER TABLE `mantenimiento`
  ADD PRIMARY KEY (`id_mantenimientos`),
  ADD UNIQUE KEY `uq_mantenimiento_mensual` (`condominio_id`,`tipo`,`periodo`),
  ADD KEY `suplidor_id` (`suplidor_id`);

--
-- Indices de la tabla `mantenimiento_solicitud`
--
ALTER TABLE `mantenimiento_solicitud`
  ADD PRIMARY KEY (`id_mantenimiento_solicitud`),
  ADD KEY `mantenimiento_id` (`mantenimiento_id`),
  ADD KEY `solicitud_id` (`solicitud_id`);

--
-- Indices de la tabla `mensaje_ia`
--
ALTER TABLE `mensaje_ia`
  ADD PRIMARY KEY (`id_mensajes`),
  ADD KEY `conversacion_id` (`conversacion_id`);

--
-- Indices de la tabla `notificacion`
--
ALTER TABLE `notificacion`
  ADD PRIMARY KEY (`id_notificaciones`),
  ADD KEY `usuario_id` (`usuario_id`);

--
-- Indices de la tabla `ocupacion`
--
ALTER TABLE `ocupacion`
  ADD PRIMARY KEY (`id_ocupaciones`),
  ADD KEY `apartamento_id` (`apartamento_id`),
  ADD KEY `persona_id` (`persona_id`);

--
-- Indices de la tabla `pago`
--
ALTER TABLE `pago`
  ADD PRIMARY KEY (`id_pagos`),
  ADD KEY `persona_id` (`persona_id`),
  ADD KEY `apartamento_id` (`apartamento_id`),
  ADD KEY `contrato_id` (`contrato_id`),
  ADD KEY `concepto_id` (`concepto_id`);

--
-- Indices de la tabla `persona`
--
ALTER TABLE `persona`
  ADD PRIMARY KEY (`id_personas`),
  ADD UNIQUE KEY `cedula` (`cedula`);

--
-- Indices de la tabla `persona_rol`
--
ALTER TABLE `persona_rol`
  ADD PRIMARY KEY (`id_persona_rol`),
  ADD KEY `persona_id` (`persona_id`);

--
-- Indices de la tabla `propiedad`
--
ALTER TABLE `propiedad`
  ADD PRIMARY KEY (`id_propiedades`),
  ADD KEY `apartamento_id` (`apartamento_id`);

--
-- Indices de la tabla `renovacion_contrato`
--
ALTER TABLE `renovacion_contrato`
  ADD PRIMARY KEY (`id_renovaciones`),
  ADD KEY `contrato_id` (`contrato_id`);

--
-- Indices de la tabla `role`
--
ALTER TABLE `role`
  ADD PRIMARY KEY (`id_roles`),
  ADD UNIQUE KEY `nombre` (`nombre`);

--
-- Indices de la tabla `solicitud_mantenimiento`
--
ALTER TABLE `solicitud_mantenimiento`
  ADD PRIMARY KEY (`id_solicitudes`),
  ADD KEY `apartamento_id` (`apartamento_id`),
  ADD KEY `persona_id` (`persona_id`),
  ADD KEY `suplidor_id` (`suplidor_id`);

--
-- Indices de la tabla `solicitud_propiedad`
--
ALTER TABLE `solicitud_propiedad`
  ADD PRIMARY KEY (`id_solicitudes`),
  ADD KEY `persona_id` (`persona_id`),
  ADD KEY `apartamento_id` (`apartamento_id`);

--
-- Indices de la tabla `suplidor`
--
ALTER TABLE `suplidor`
  ADD PRIMARY KEY (`id_suplidores`);

--
-- Indices de la tabla `suscripcion`
--
ALTER TABLE `suscripcion`
  ADD PRIMARY KEY (`id_suscripcion`),
  ADD UNIQUE KEY `paypal_subscription_id` (`paypal_subscription_id`),
  ADD KEY `usuario_id` (`usuario_id`);

--
-- Indices de la tabla `transaccion_paypal`
--
ALTER TABLE `transaccion_paypal`
  ADD PRIMARY KEY (`id_transacciones`),
  ADD UNIQUE KEY `codigo_transaccion` (`codigo_transaccion`),
  ADD KEY `pago_id` (`pago_id`);

--
-- Indices de la tabla `usuario`
--
ALTER TABLE `usuario`
  ADD PRIMARY KEY (`id_usuarios`),
  ADD UNIQUE KEY `usuario` (`usuario`),
  ADD KEY `id_rol` (`id_rol`),
  ADD KEY `persona_id` (`persona_id`);

--
-- Indices de la tabla `viora_factura`
--
ALTER TABLE `viora_factura`
  ADD PRIMARY KEY (`id_factura`),
  ADD UNIQUE KEY `uq_viora_factura_contrato_periodo` (`contrato_id`,`periodo`),
  ADD KEY `persona_id` (`persona_id`);

--
-- Indices de la tabla `viora_factura_detalle`
--
ALTER TABLE `viora_factura_detalle`
  ADD PRIMARY KEY (`id_detalle`),
  ADD KEY `idx_viora_factura_detalle_factura` (`factura_id`);

--
-- Indices de la tabla `viora_pago`
--
ALTER TABLE `viora_pago`
  ADD PRIMARY KEY (`id_pago`),
  ADD UNIQUE KEY `referencia` (`referencia`),
  ADD KEY `factura_id` (`factura_id`),
  ADD KEY `persona_id` (`persona_id`);

--
-- Indices de la tabla `visita_propiedad`
--
ALTER TABLE `visita_propiedad`
  ADD PRIMARY KEY (`id_visita`),
  ADD KEY `apartamento_id` (`apartamento_id`),
  ADD KEY `persona_id` (`persona_id`);

--
-- AUTO_INCREMENT de las tablas volcadas
--

--
-- AUTO_INCREMENT de la tabla `apartamento`
--
ALTER TABLE `apartamento`
  MODIFY `id_apartamentos` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT de la tabla `comprobante_fiscal`
--
ALTER TABLE `comprobante_fiscal`
  MODIFY `id_comprobantes` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT de la tabla `concepto_pago`
--
ALTER TABLE `concepto_pago`
  MODIFY `id_conceptos_pago` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT de la tabla `condominio`
--
ALTER TABLE `condominio`
  MODIFY `id_condominios` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT de la tabla `contrato`
--
ALTER TABLE `contrato`
  MODIFY `id_contratos` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT de la tabla `contrato_detalle_persona`
--
ALTER TABLE `contrato_detalle_persona`
  MODIFY `id_contrato_persona` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT de la tabla `conversacion_ia`
--
ALTER TABLE `conversacion_ia`
  MODIFY `id_conversaciones` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT de la tabla `documento`
--
ALTER TABLE `documento`
  MODIFY `id_documentos` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT de la tabla `edificio`
--
ALTER TABLE `edificio`
  MODIFY `id_edificios` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT de la tabla `factura`
--
ALTER TABLE `factura`
  MODIFY `id_facturas` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=14;

--
-- AUTO_INCREMENT de la tabla `factura_detalle`
--
ALTER TABLE `factura_detalle`
  MODIFY `id_detalles` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=27;

--
-- AUTO_INCREMENT de la tabla `factura_ecf`
--
ALTER TABLE `factura_ecf`
  MODIFY `id_factura_ecf` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=14;

--
-- AUTO_INCREMENT de la tabla `factura_ecf_nota`
--
ALTER TABLE `factura_ecf_nota`
  MODIFY `id_factura_ecf_nota` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT de la tabla `factura_pago`
--
ALTER TABLE `factura_pago`
  MODIFY `id_pagos` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `mantenimiento`
--
ALTER TABLE `mantenimiento`
  MODIFY `id_mantenimientos` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT de la tabla `mantenimiento_solicitud`
--
ALTER TABLE `mantenimiento_solicitud`
  MODIFY `id_mantenimiento_solicitud` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT de la tabla `mensaje_ia`
--
ALTER TABLE `mensaje_ia`
  MODIFY `id_mensajes` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT de la tabla `notificacion`
--
ALTER TABLE `notificacion`
  MODIFY `id_notificaciones` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT de la tabla `ocupacion`
--
ALTER TABLE `ocupacion`
  MODIFY `id_ocupaciones` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT de la tabla `pago`
--
ALTER TABLE `pago`
  MODIFY `id_pagos` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT de la tabla `persona`
--
ALTER TABLE `persona`
  MODIFY `id_personas` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=16;

--
-- AUTO_INCREMENT de la tabla `persona_rol`
--
ALTER TABLE `persona_rol`
  MODIFY `id_persona_rol` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT de la tabla `propiedad`
--
ALTER TABLE `propiedad`
  MODIFY `id_propiedades` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT de la tabla `renovacion_contrato`
--
ALTER TABLE `renovacion_contrato`
  MODIFY `id_renovaciones` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT de la tabla `role`
--
ALTER TABLE `role`
  MODIFY `id_roles` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT de la tabla `solicitud_mantenimiento`
--
ALTER TABLE `solicitud_mantenimiento`
  MODIFY `id_solicitudes` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT de la tabla `solicitud_propiedad`
--
ALTER TABLE `solicitud_propiedad`
  MODIFY `id_solicitudes` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT de la tabla `suplidor`
--
ALTER TABLE `suplidor`
  MODIFY `id_suplidores` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT de la tabla `suscripcion`
--
ALTER TABLE `suscripcion`
  MODIFY `id_suscripcion` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT de la tabla `transaccion_paypal`
--
ALTER TABLE `transaccion_paypal`
  MODIFY `id_transacciones` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT de la tabla `usuario`
--
ALTER TABLE `usuario`
  MODIFY `id_usuarios` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT de la tabla `viora_factura`
--
ALTER TABLE `viora_factura`
  MODIFY `id_factura` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT de la tabla `viora_factura_detalle`
--
ALTER TABLE `viora_factura_detalle`
  MODIFY `id_detalle` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT de la tabla `viora_pago`
--
ALTER TABLE `viora_pago`
  MODIFY `id_pago` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `visita_propiedad`
--
ALTER TABLE `visita_propiedad`
  MODIFY `id_visita` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- Restricciones para tablas volcadas
--

--
-- Filtros para la tabla `apartamento`
--
ALTER TABLE `apartamento`
  ADD CONSTRAINT `apartamento_ibfk_1` FOREIGN KEY (`edificio_id`) REFERENCES `edificio` (`id_edificios`);

--
-- Filtros para la tabla `comprobante_fiscal`
--
ALTER TABLE `comprobante_fiscal`
  ADD CONSTRAINT `comprobante_fiscal_ibfk_1` FOREIGN KEY (`pago_id`) REFERENCES `pago` (`id_pagos`);

--
-- Filtros para la tabla `contrato`
--
ALTER TABLE `contrato`
  ADD CONSTRAINT `contrato_ibfk_1` FOREIGN KEY (`apartamento_id`) REFERENCES `apartamento` (`id_apartamentos`),
  ADD CONSTRAINT `contrato_ibfk_2` FOREIGN KEY (`propiedad_id`) REFERENCES `propiedad` (`id_propiedades`),
  ADD CONSTRAINT `fk_contrato_persona` FOREIGN KEY (`persona_id`) REFERENCES `persona` (`id_personas`),
  ADD CONSTRAINT `fk_contrato_solicitud` FOREIGN KEY (`solicitud_id`) REFERENCES `solicitud_propiedad` (`id_solicitudes`);

--
-- Filtros para la tabla `contrato_detalle_persona`
--
ALTER TABLE `contrato_detalle_persona`
  ADD CONSTRAINT `contrato_detalle_persona_ibfk_1` FOREIGN KEY (`contrato_id`) REFERENCES `contrato` (`id_contratos`),
  ADD CONSTRAINT `contrato_detalle_persona_ibfk_2` FOREIGN KEY (`persona_id`) REFERENCES `persona` (`id_personas`);

--
-- Filtros para la tabla `conversacion_ia`
--
ALTER TABLE `conversacion_ia`
  ADD CONSTRAINT `conversacion_ia_ibfk_1` FOREIGN KEY (`usuario_id`) REFERENCES `usuario` (`id_usuarios`);

--
-- Filtros para la tabla `documento`
--
ALTER TABLE `documento`
  ADD CONSTRAINT `documento_ibfk_1` FOREIGN KEY (`contrato_id`) REFERENCES `contrato` (`id_contratos`),
  ADD CONSTRAINT `documento_ibfk_2` FOREIGN KEY (`persona_id`) REFERENCES `persona` (`id_personas`);

--
-- Filtros para la tabla `edificio`
--
ALTER TABLE `edificio`
  ADD CONSTRAINT `edificio_ibfk_1` FOREIGN KEY (`condominio_id`) REFERENCES `condominio` (`id_condominios`);

--
-- Filtros para la tabla `factura`
--
ALTER TABLE `factura`
  ADD CONSTRAINT `factura_ibfk_1` FOREIGN KEY (`contrato_id`) REFERENCES `contrato` (`id_contratos`),
  ADD CONSTRAINT `factura_ibfk_2` FOREIGN KEY (`persona_id`) REFERENCES `persona` (`id_personas`);

--
-- Filtros para la tabla `factura_detalle`
--
ALTER TABLE `factura_detalle`
  ADD CONSTRAINT `factura_detalle_ibfk_1` FOREIGN KEY (`factura_id`) REFERENCES `factura` (`id_facturas`);

--
-- Filtros para la tabla `factura_ecf`
--
ALTER TABLE `factura_ecf`
  ADD CONSTRAINT `fk_factura_ecf_factura` FOREIGN KEY (`factura_id`) REFERENCES `factura` (`id_facturas`);

--
-- Filtros para la tabla `factura_ecf_nota`
--
ALTER TABLE `factura_ecf_nota`
  ADD CONSTRAINT `fk_factura_ecf_nota_factura` FOREIGN KEY (`factura_id`) REFERENCES `factura` (`id_facturas`);

--
-- Filtros para la tabla `factura_pago`
--
ALTER TABLE `factura_pago`
  ADD CONSTRAINT `factura_pago_ibfk_1` FOREIGN KEY (`factura_id`) REFERENCES `factura` (`id_facturas`);

--
-- Filtros para la tabla `mantenimiento`
--
ALTER TABLE `mantenimiento`
  ADD CONSTRAINT `mantenimiento_ibfk_1` FOREIGN KEY (`condominio_id`) REFERENCES `condominio` (`id_condominios`),
  ADD CONSTRAINT `mantenimiento_ibfk_2` FOREIGN KEY (`suplidor_id`) REFERENCES `suplidor` (`id_suplidores`);

--
-- Filtros para la tabla `mantenimiento_solicitud`
--
ALTER TABLE `mantenimiento_solicitud`
  ADD CONSTRAINT `mantenimiento_solicitud_ibfk_1` FOREIGN KEY (`mantenimiento_id`) REFERENCES `mantenimiento` (`id_mantenimientos`),
  ADD CONSTRAINT `mantenimiento_solicitud_ibfk_2` FOREIGN KEY (`solicitud_id`) REFERENCES `solicitud_mantenimiento` (`id_solicitudes`);

--
-- Filtros para la tabla `mensaje_ia`
--
ALTER TABLE `mensaje_ia`
  ADD CONSTRAINT `mensaje_ia_ibfk_1` FOREIGN KEY (`conversacion_id`) REFERENCES `conversacion_ia` (`id_conversaciones`);

--
-- Filtros para la tabla `notificacion`
--
ALTER TABLE `notificacion`
  ADD CONSTRAINT `notificacion_ibfk_1` FOREIGN KEY (`usuario_id`) REFERENCES `usuario` (`id_usuarios`);

--
-- Filtros para la tabla `ocupacion`
--
ALTER TABLE `ocupacion`
  ADD CONSTRAINT `ocupacion_ibfk_1` FOREIGN KEY (`apartamento_id`) REFERENCES `apartamento` (`id_apartamentos`),
  ADD CONSTRAINT `ocupacion_ibfk_2` FOREIGN KEY (`persona_id`) REFERENCES `persona` (`id_personas`);

--
-- Filtros para la tabla `pago`
--
ALTER TABLE `pago`
  ADD CONSTRAINT `pago_ibfk_1` FOREIGN KEY (`persona_id`) REFERENCES `persona` (`id_personas`),
  ADD CONSTRAINT `pago_ibfk_2` FOREIGN KEY (`apartamento_id`) REFERENCES `apartamento` (`id_apartamentos`),
  ADD CONSTRAINT `pago_ibfk_3` FOREIGN KEY (`contrato_id`) REFERENCES `contrato` (`id_contratos`),
  ADD CONSTRAINT `pago_ibfk_4` FOREIGN KEY (`concepto_id`) REFERENCES `concepto_pago` (`id_conceptos_pago`);

--
-- Filtros para la tabla `persona_rol`
--
ALTER TABLE `persona_rol`
  ADD CONSTRAINT `persona_rol_ibfk_1` FOREIGN KEY (`persona_id`) REFERENCES `persona` (`id_personas`);

--
-- Filtros para la tabla `propiedad`
--
ALTER TABLE `propiedad`
  ADD CONSTRAINT `propiedad_ibfk_1` FOREIGN KEY (`apartamento_id`) REFERENCES `apartamento` (`id_apartamentos`);

--
-- Filtros para la tabla `renovacion_contrato`
--
ALTER TABLE `renovacion_contrato`
  ADD CONSTRAINT `renovacion_contrato_ibfk_1` FOREIGN KEY (`contrato_id`) REFERENCES `contrato` (`id_contratos`);

--
-- Filtros para la tabla `solicitud_mantenimiento`
--
ALTER TABLE `solicitud_mantenimiento`
  ADD CONSTRAINT `fk_solicitud_mantenimiento_suplidor` FOREIGN KEY (`suplidor_id`) REFERENCES `suplidor` (`id_suplidores`),
  ADD CONSTRAINT `solicitud_mantenimiento_ibfk_1` FOREIGN KEY (`apartamento_id`) REFERENCES `apartamento` (`id_apartamentos`),
  ADD CONSTRAINT `solicitud_mantenimiento_ibfk_2` FOREIGN KEY (`persona_id`) REFERENCES `persona` (`id_personas`);

--
-- Filtros para la tabla `solicitud_propiedad`
--
ALTER TABLE `solicitud_propiedad`
  ADD CONSTRAINT `solicitud_propiedad_ibfk_1` FOREIGN KEY (`persona_id`) REFERENCES `persona` (`id_personas`),
  ADD CONSTRAINT `solicitud_propiedad_ibfk_2` FOREIGN KEY (`apartamento_id`) REFERENCES `apartamento` (`id_apartamentos`);

--
-- Filtros para la tabla `suscripcion`
--
ALTER TABLE `suscripcion`
  ADD CONSTRAINT `suscripcion_ibfk_1` FOREIGN KEY (`usuario_id`) REFERENCES `usuario` (`id_usuarios`);

--
-- Filtros para la tabla `transaccion_paypal`
--
ALTER TABLE `transaccion_paypal`
  ADD CONSTRAINT `transaccion_paypal_ibfk_1` FOREIGN KEY (`pago_id`) REFERENCES `pago` (`id_pagos`);

--
-- Filtros para la tabla `usuario`
--
ALTER TABLE `usuario`
  ADD CONSTRAINT `usuario_ibfk_1` FOREIGN KEY (`id_rol`) REFERENCES `role` (`id_roles`),
  ADD CONSTRAINT `usuario_ibfk_2` FOREIGN KEY (`persona_id`) REFERENCES `persona` (`id_personas`);

--
-- Filtros para la tabla `viora_factura`
--
ALTER TABLE `viora_factura`
  ADD CONSTRAINT `viora_factura_ibfk_1` FOREIGN KEY (`contrato_id`) REFERENCES `contrato` (`id_contratos`),
  ADD CONSTRAINT `viora_factura_ibfk_2` FOREIGN KEY (`persona_id`) REFERENCES `persona` (`id_personas`);

--
-- Filtros para la tabla `viora_factura_detalle`
--
ALTER TABLE `viora_factura_detalle`
  ADD CONSTRAINT `fk_viora_detalle_factura` FOREIGN KEY (`factura_id`) REFERENCES `viora_factura` (`id_factura`);

--
-- Filtros para la tabla `viora_pago`
--
ALTER TABLE `viora_pago`
  ADD CONSTRAINT `viora_pago_ibfk_1` FOREIGN KEY (`factura_id`) REFERENCES `viora_factura` (`id_factura`),
  ADD CONSTRAINT `viora_pago_ibfk_2` FOREIGN KEY (`persona_id`) REFERENCES `persona` (`id_personas`);

--
-- Filtros para la tabla `visita_propiedad`
--
ALTER TABLE `visita_propiedad`
  ADD CONSTRAINT `visita_propiedad_ibfk_1` FOREIGN KEY (`apartamento_id`) REFERENCES `apartamento` (`id_apartamentos`),
  ADD CONSTRAINT `visita_propiedad_ibfk_2` FOREIGN KEY (`persona_id`) REFERENCES `persona` (`id_personas`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
