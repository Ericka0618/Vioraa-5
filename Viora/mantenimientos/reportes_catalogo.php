<?php
/** Catálogo compartido por interfaz, consultas autorizadas y Gemini. */
function bi_catalogo(): array {
    $grupos = [
        'Finanzas' => ['Zonas con mayor rentabilidad','Zonas con menor rentabilidad','Condominios con mayor rentabilidad','Propiedades que generan mayores ingresos','Comparación de ingresos y gastos por condominio','Ingresos mensuales por alquiler','Ingresos por venta de propiedades','Evolución mensual y anual de los ingresos','Proyección de ingresos futuros','Rentabilidad según el tipo de propiedad'],
        'Propiedades' => ['Propiedades disponibles por zona','Propiedades ocupadas por zona','Porcentaje de ocupación por condominio','Zonas con mayor demanda de propiedades','Propiedades con mayor tiempo sin ocupar','Precio promedio de alquiler por zona','Precio promedio de venta por zona','Comparación entre propiedades vendidas y alquiladas'],
        'Clientes' => ['Clientes con mayor historial de pagos','Clientes con pagos pendientes o atrasados','Cantidad de clientes activos e inactivos','Solicitudes aprobadas, rechazadas y pendientes','Zonas con mayor cantidad de solicitudes'],
        'Facturación' => ['Facturas pagadas y pendientes','Cuentas por cobrar por cliente y condominio','Métodos de pago más utilizados','Comportamiento mensual de los cobros'],
        'Mantenimiento' => ['Condominios con mayores gastos de mantenimiento','Solicitudes de mantenimiento por estado, frecuencia y propiedad','Ingresos, renovaciones, vencimientos y cancelaciones de suscripciones'],
    ];
    $descripciones = [
        'Compara el margen neto de los cobros registrados, descontando gastos operativos y mantenimientos realizados.',
        'Identifica las zonas con menor margen neto y las que requieren completar información de gastos.',
        'Ordena los condominios por margen neto e incluye beneficio, inversión registrada y ROI.',
        'Ordena las propiedades por ingresos efectivamente cobrados; los gastos comunes permanecen a nivel de condominio.',
        'Contrasta cobros con gastos operativos y mantenimientos realizados, sin duplicar pagos.',
        'Agrupa cobros de contratos de alquiler por mes de pago; incluye los conceptos de sus facturas.',
        'Muestra cobros de contratos de venta, sin confundir el precio publicado con ingresos reales.',
        'Compara los ingresos cobrados por mes y año, incluyendo meses sin cobros.',
        'Estima hasta doce meses con el promedio de los últimos tres meses completos del período. No garantiza ingresos.',
        'Distingue contratos de alquiler y venta. Los gastos comunes sin atribución se explican por separado.',
        'Cuenta apartamentos activos con estado Disponible, agrupados por ciudad del condominio.',
        'Cuenta ocupaciones vigentes a la fecha final; excluye propiedades vendidas de la base de alquiler.',
        'Calcula ocupadas / apartamentos activos para ocupación, excluyendo vendidos y los destinados solo a venta.',
        'Mide demanda por solicitudes recibidas en el período, incluidas zonas sin solicitudes.',
        'Mide días desde la última salida o publicación conocida. Si no existe fecha, muestra dato faltante.',
        'Promedia precios publicados de alquiler positivos; no representa cobros ni beneficio.',
        'Promedia precios publicados de venta positivos; no representa ingresos efectivamente cobrados.',
        'Compara operaciones de venta y alquiler registradas en contratos iniciados en el período.',
        'Agrupa pagos registrados por cliente, sin exponer cédula, correo ni teléfono.',
        'Muestra saldos actuales de facturas emitidas en el período y distingue los vencidos.',
        'Considera clientes vinculados a contratos o solicitudes; el estado es el actual.',
        'Agrupa las solicitudes recibidas por su estado actual.',
        'Cuenta solicitudes recibidas por zona geográfica.',
        'Compara importes facturados y saldos actuales, excluyendo borradores y facturas anuladas.',
        'Detalla saldo pendiente actual por cliente y condominio de facturas del período.',
        'Cuenta pagos y suma cobros por método registrado en factura_pago.',
        'Agrupa cobros reales por mes de fecha_pago, con meses sin actividad.',
        'Suma costos de mantenimiento realizado, por fecha de realización, sin incluir trabajos programados.',
        'Agrupa solicitudes de reparación por mes, propiedad y estado actual.',
        'Separa monedas. Los ingresos provienen de pagos de suscripciones confirmados; las fechas próximas no prueban una renovación.',
    ];
    $out=[]; $id=0;
    foreach ($grupos as $categoria=>$nombres) foreach ($nombres as $nombre) { $id++; $out[$id]=['id'=>$id,'nombre'=>$nombre,'categoria'=>$categoria,'descripcion'=>$descripciones[$id-1]]; }
    return $out;
}
