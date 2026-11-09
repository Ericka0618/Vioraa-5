

<?php
// mantenimientos/menu_lateral.php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$paginaActual = basename($_SERVER['PHP_SELF'] ?? '');

$rol = $_SESSION['rol'] ?? '';

$esAdministrador = (strcasecmp($rol, 'Administrador') === 0 ||
                    strcasecmp($rol, 'Admin') === 0);

$esCliente = (strcasecmp($rol, 'Cliente') === 0);

// No mostrar el menú a usuarios sin sesión
if (
    empty($_SESSION['logged_in']) ||
    (!$esAdministrador && !$esCliente)
) {
    return;
}

// Identificar la sección activa del administrador
$paginasRegistrar = [
    'apartamento.php',
    'edificio.php',
    'condominio.php',
    'clientes.php'
];

$paginasOperaciones = [
    'contratos.php',
    'solicitudes.php',
    'reparaciones.php',
    'mantenimiento_mensual.php',
    'catalogo.php',
    'consulta_visitas.php',
    'vencimiento_contratos.php',
    'facturacion_viora.php'
];

$paginasConsultas = [
    'consulta_cotizaciones.php',
    'consulta_compras.php',
    'consulta_movimientos.php'
];

$paginasCobros = [
    'cxc_clientes.php',
    'clientes_consulta.php',
    'consulta_cobros.php'
];

$paginasConfiguracion = [
    'usuarios.php',
    'roles.php'
];

?>

<aside class="barra-lateral" aria-label="Menú de VIORA">

    <!-- MARCA -->
    <a class="marca"
       href="<?= $esCliente
           ? '../mantenimientos/catalogo.php'
           : '../login/principal.php' ?>">

        <span class="marca__nombre">VIORA</span>

        <span class="marca__subtitulo">
            Administración de condominios
        </span>
    </a>

    <?php if ($esAdministrador): ?>

        <!-- INICIO -->
        <a href="../login/principal.php"
           class="menu__enlace menu__inicio
           <?= $paginaActual === 'principal.php' ? 'activo' : '' ?>">

            <span class="menu__icono">⌂</span>
            <span>Inicio</span>
        </a>

        <!-- MANTENIMIENTOS -->
        <div class="menu__grupo-titulo">
            MANTENIMIENTOS
        </div>

        <details class="desplegable_bar"
            <?= in_array($paginaActual, $paginasRegistrar, true) ? 'open' : '' ?>>

            <summary class="desplegable_bar__resumen">
                <span class="menu__icono">▦</span>
                <span class="desplegable_bar__titulo">Registrar</span>
                <span class="desplegable_bar__flecha">▾</span>
            </summary>

            <nav class="desplegable_bar__lista">

                <a href="../mantenimientos/apartamento.php"
                   class="menu__enlace <?= $paginaActual === 'apartamento.php' ? 'activo' : '' ?>">
                    Apartamentos
                </a>

                <a href="../mantenimientos/edificio.php"
                   class="menu__enlace <?= $paginaActual === 'edificio.php' ? 'activo' : '' ?>">
                    Edificios
                </a>

                <a href="../mantenimientos/condominio.php"
                   class="menu__enlace <?= $paginaActual === 'condominio.php' ? 'activo' : '' ?>">
                    Condominios
                </a>

                <a href="../mantenimientos/clientes.php"
                   class="menu__enlace <?= $paginaActual === 'clientes.php' ? 'activo' : '' ?>">
                    Clientes
                </a>

            </nav>
        </details>

        <!-- PROCESOS -->
        <div class="menu__grupo-titulo">
            PROCESOS
        </div>

        <details class="desplegable_bar"
            <?= in_array($paginaActual, $paginasOperaciones, true) ? 'open' : '' ?>>

            <summary class="desplegable_bar__resumen">
                <span class="menu__icono">⇄</span>
                <span class="desplegable_bar__titulo">Operaciones</span>
                <span class="desplegable_bar__flecha">▾</span>
            </summary>

            <nav class="desplegable_bar__lista">

             <a href="../mantenimientos/catalogo.php"
                   class="menu__enlace <?= $paginaActual === 'catalogo.php' ? 'activo' : '' ?>">
                    Propiedades Disponibles
                </a>

            <a href="../mantenimientos/solicitudes.php"
                   class="menu__enlace <?= $paginaActual === 'solicitudes.php' ? 'activo' : '' ?>">
                    Solicitud Comprar/Rentar
                </a>

                <a href="../mantenimientos/consulta_visitas.php"
                   class="menu__enlace <?= $paginaActual === 'consulta_visitas.php' ? 'activo' : '' ?>">
                    Consulta de visitas
                </a>

                <a href="../mantenimientos/contratos.php"
                   class="menu__enlace <?= $paginaActual === 'contratos.php' ? 'activo' : '' ?>">
                    Contratos aprobados
                </a>

                <a href="../mantenimientos/vencimiento_contratos.php"
                   class="menu__enlace <?= $paginaActual === 'vencimiento_contratos.php' ? 'activo' : '' ?>">
                    Vencimiento de contratos
                </a>
                <a href="../mantenimientos/mantenimiento_mensual.php"
                                class="menu__enlace <?= $paginaActual === 'mantenimiento_mensual.php' ? 'activo' : '' ?>">
                                    Mantenimiento mensual
                                </a>

                <a href="../mantenimientos/reparaciones.php"
                   class="menu__enlace <?= $paginaActual === 'reparaciones.php' ? 'activo' : '' ?>">
                    Reparaciones
                </a>

                <a href="../mantenimientos/facturacion.php"
                   class="menu__enlace <?= $paginaActual === 'facturacion.php' ? 'activo' : '' ?>">
                    Facturación Mensual
                </a>

                <a href="../mantenimientos/consulta_facturas.php"
                   class="menu__enlace <?= $paginaActual === 'consulta_facturas.php' ? 'activo' : '' ?>">
                    Consulta de facturas
                </a>

            </nav>
        </details>

        <!-- CUENTAS POR COBRAR -->
        <div class="menu__grupo-titulo">
            CUENTAS POR COBRAR
        </div>

        <details class="desplegable_bar"
            <?= in_array($paginaActual, $paginasCobros, true) ? 'open' : '' ?>>

            <summary class="desplegable_bar__resumen">
                <span class="menu__icono">$</span>
                <span class="desplegable_bar__titulo">
                    Clientes y cobros
                </span>
                <span class="desplegable_bar__flecha">▾</span>
            </summary>

            <nav class="desplegable_bar__lista">

                <a href="../mantenimientos/cxc_clientes.php" class="menu__enlace">
                    Cuentas por cobrar Inquilinos
                </a>

                <a href="../mantenimientos/clientes_consulta.php"
                   class="menu__enlace <?= $paginaActual === 'clientes_consulta.php' ? 'activo' : '' ?>">
                    Consultas de clientes
                </a>

                <a href="../mantenimientos/consulta_cobros.php"
                   class="menu__enlace <?= $paginaActual === 'consulta_cobros.php' ? 'activo' : '' ?>">
                    Consultas de cobros
                </a>

            </nav>
        </details>

        <!-- CONFIGURACIÓN -->
        <div class="menu__grupo-titulo">
            CONFIGURACIÓN
        </div>

        <details class="desplegable_bar"
            <?= in_array($paginaActual, $paginasConfiguracion, true) ? 'open' : '' ?>>

            <summary class="desplegable_bar__resumen">
                <span class="menu__icono">⚙</span>
                <span class="desplegable_bar__titulo">
                    Configuración
                </span>
                <span class="desplegable_bar__flecha">▾</span>
            </summary>

            <nav class="desplegable_bar__lista">

                <a href="../Configuracion/usuarios.php"
                   class="menu__enlace <?= $paginaActual === 'usuarios.php' ? 'activo' : '' ?>">
                    Usuarios
                </a>

                <a href="../Configuracion/roles.php"
                   class="menu__enlace <?= $paginaActual === 'roles.php' ? 'activo' : '' ?>">
                    Roles
                </a>

            </nav>
        </details>

    <?php endif; ?>


    <!-- ================================= -->
    <!-- MENÚ EXCLUSIVO DEL CLIENTE -->
    <!-- ================================= -->

    <?php if ($esCliente): ?>

        <div class="menu__grupo-titulo">
            PROPIEDADES
        </div>

        <!-- PROPIEDADES DISPONIBLES -->
        <a href="../mantenimientos/catalogo.php"
           class="menu__enlace <?= $paginaActual === 'catalogo.php' ? 'activo' : '' ?>">

            <span class="menu__icono">⌂</span>
            <span>Propiedades disponibles</span>
        </a>

        <!-- ADMINISTRAR SOLICITUDES -->
        <a href="../mantenimientos/administrar_solicitudes.php"
           class="menu__enlace <?= $paginaActual === 'administrar_solicitudes.php' ? 'activo' : '' ?>">

            <span class="menu__icono">▤</span>
            <span>Administrar solicitudes</span>
        </a>

        <!-- ADMINISTRAR MI PROPIEDAD -->
        <a href="../mantenimientos/administrar_propiedad.php"
           class="menu__enlace <?= $paginaActual === 'administrar_propiedad.php' ? 'activo' : '' ?>">

            <span class="menu__icono">⌂</span>
            <span>Administrar mi propiedad</span>
        </a>

        <!-- SOLICITAR UNA REPARACIÓN -->
        <a href="../mantenimientos/solicitar_reparacion.php"
           class="menu__enlace <?= $paginaActual === 'solicitar_reparacion.php' ? 'activo' : '' ?>">

            <span class="menu__icono">🔧</span>
            <span>Solicitar una reparación</span>
        </a>

    <?php endif; ?>


    <!-- CERRAR SESIÓN -->
    <div class="barra-lateral__abajo">

        <a class="cerrar-sesion"
           href="../login/logout.php">

            <span>↪</span>
            Cerrar sesión
        </a>

    </div>

</aside>

<link rel="stylesheet" href="../estilos/moderno.css?v=1">
<?php include __DIR__ . '/chatbot_widget.php'; ?>
