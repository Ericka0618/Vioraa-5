<?php
/** CLI only. Run a database backup before invoking this additive, repeatable migration. */
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
require __DIR__.'/../Viora/conf/conexion.php';
$db=$conexion;
$db->query("CREATE TABLE IF NOT EXISTS bi_propiedad_info (apartamento_id BIGINT PRIMARY KEY,tipo VARCHAR(60) NOT NULL DEFAULT 'Apartamento',disponible_desde DATE NULL,FOREIGN KEY(apartamento_id) REFERENCES apartamento(id_apartamentos)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$db->query("CREATE TABLE IF NOT EXISTS bi_gasto (id BIGINT PRIMARY KEY AUTO_INCREMENT,condominio_id BIGINT NOT NULL,apartamento_id BIGINT NULL,fecha DATE NOT NULL,monto DECIMAL(18,2) NOT NULL,concepto VARCHAR(200) NOT NULL,referencia VARCHAR(100) NULL UNIQUE,CONSTRAINT bi_gasto_cond FOREIGN KEY(condominio_id) REFERENCES condominio(id_condominios),CONSTRAINT bi_gasto_apt FOREIGN KEY(apartamento_id) REFERENCES apartamento(id_apartamentos),CHECK(monto>=0),INDEX(fecha)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$db->query("CREATE TABLE IF NOT EXISTS bi_inversion (id BIGINT PRIMARY KEY AUTO_INCREMENT,condominio_id BIGINT NOT NULL,apartamento_id BIGINT NULL,fecha DATE NOT NULL,monto DECIMAL(18,2) NOT NULL,concepto VARCHAR(200) NOT NULL,referencia VARCHAR(100) NULL UNIQUE,CONSTRAINT bi_inv_cond FOREIGN KEY(condominio_id) REFERENCES condominio(id_condominios),CONSTRAINT bi_inv_apt FOREIGN KEY(apartamento_id) REFERENCES apartamento(id_apartamentos),CHECK(monto>=0),INDEX(fecha)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$db->query("CREATE TABLE IF NOT EXISTS bi_suscripcion_pago (id BIGINT PRIMARY KEY AUTO_INCREMENT,suscripcion_id BIGINT NOT NULL,fecha DATETIME NOT NULL,monto DECIMAL(18,2) NOT NULL,moneda CHAR(3) NOT NULL,referencia VARCHAR(150) NOT NULL UNIQUE,CONSTRAINT bi_sub_pago FOREIGN KEY(suscripcion_id) REFERENCES suscripcion(id_suscripcion),CHECK(monto>=0),INDEX(fecha)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$db->query("CREATE TABLE IF NOT EXISTS bi_reporte_historial (id BIGINT PRIMARY KEY AUTO_INCREMENT,reporte_id INT NOT NULL,nombre VARCHAR(180) NOT NULL,usuario_id BIGINT NOT NULL,filtros JSON NOT NULL,metodo ENUM('manual','ia') NOT NULL,creado DATETIME NOT NULL,resultado JSON NOT NULL,pdf LONGBLOB NOT NULL,sha256 CHAR(64) NOT NULL,archivo VARCHAR(200) NOT NULL,INDEX(usuario_id,creado),FOREIGN KEY(usuario_id) REFERENCES usuario(id_usuarios)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
// Isolated clones cannot be discovered by existing invoice/payment/notification jobs.
$tables=['condominio','edificio','apartamento','propiedad','persona','usuario','contrato','factura','factura_pago','ocupacion','solicitud_propiedad','solicitud_mantenimiento','mantenimiento','suscripcion'];
foreach($tables as $t)$db->query("CREATE TABLE IF NOT EXISTS bi_demo_{$t} LIKE {$t}");
foreach(['gasto','inversion','suscripcion_pago'] as $t)$db->query("CREATE TABLE IF NOT EXISTS bi_demo_{$t} LIKE bi_{$t}");
$db->query('CREATE TABLE IF NOT EXISTS bi_demo_propiedad_info LIKE bi_propiedad_info');
$db->query("CREATE TABLE IF NOT EXISTS bi_demo_lote (clave VARCHAR(80) PRIMARY KEY,creado DATETIME NOT NULL,descripcion VARCHAR(255) NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
// Add actual foreign keys between clones (CREATE LIKE deliberately does not copy FKs).
$relations=[['edificio','condominio_id','condominio','id_condominios'],['apartamento','edificio_id','edificio','id_edificios'],['propiedad','apartamento_id','apartamento','id_apartamentos'],['contrato','apartamento_id','apartamento','id_apartamentos'],['contrato','persona_id','persona','id_personas'],['factura','contrato_id','contrato','id_contratos'],['factura','persona_id','persona','id_personas'],['factura_pago','factura_id','factura','id_facturas'],['ocupacion','apartamento_id','apartamento','id_apartamentos'],['ocupacion','persona_id','persona','id_personas'],['solicitud_propiedad','apartamento_id','apartamento','id_apartamentos'],['solicitud_propiedad','persona_id','persona','id_personas'],['solicitud_mantenimiento','apartamento_id','apartamento','id_apartamentos'],['solicitud_mantenimiento','persona_id','persona','id_personas'],['mantenimiento','condominio_id','condominio','id_condominios'],['gasto','condominio_id','condominio','id_condominios'],['inversion','condominio_id','condominio','id_condominios'],['suscripcion_pago','suscripcion_id','suscripcion','id_suscripcion']];
foreach($relations as $n=>[$a,$col,$b,$pk]) {
    $name='bi_demo_fk_'.$n;
    $q=$db->prepare('SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME=? AND CONSTRAINT_NAME=?');$table='bi_demo_'.$a;$q->bind_param('ss',$table,$name);$q->execute();
    if(!(int)$q->get_result()->fetch_row()[0])$db->query("ALTER TABLE bi_demo_{$a} ADD CONSTRAINT {$name} FOREIGN KEY ({$col}) REFERENCES bi_demo_{$b}({$pk})");
}
$db->begin_transaction();
try {
    $exists=$db->query("SELECT clave FROM bi_demo_lote WHERE clave='reportes-v1'")->fetch_row();
    if(!$exists) {
        $insert=function(string $t,array $r)use($db){$columns=implode(',',array_map(fn($c)=>'`'.$c.'`',array_keys($r)));$q=$db->prepare('INSERT INTO bi_demo_'.$t.' ('.$columns.') VALUES ('.implode(',',array_fill(0,count($r),'?')).')');$vals=array_values($r);$q->bind_param(str_repeat('s',count($vals)),...$vals);$q->execute();};
        $cities=['Santiago','Santo Domingo','Punta Cana','La Vega','Puerto Plata','Moca'];
        $now=new DateTimeImmutable('now',new DateTimeZone('America/Santo_Domingo'));
        for($i=1;$i<=30;$i++) {
            $date=$now->modify('first day of this month')->modify('-'.(($i-1)%10).' months')->format('Y-m-03');$city=$cities[($i-1)%6];$rent=12000+($i%9)*2200;$sale=$i%5===0;$amount=$sale?2500000+$i*25000:$rent;$paid=$i%4===0?0:($i%4===1?round($amount/2,2):$amount);$end=(new DateTimeImmutable($date))->modify('+18 months')->format('Y-m-d');
            $insert('condominio',['id_condominios'=>$i,'nombre'=>'DEMO · Residencial '.$i,'direccion'=>'Dirección de demostración '.$i,'ciudad'=>$city,'provincia'=>$city,'descripcion'=>'DEMO REPORTES · sin operaciones reales']);
            $insert('edificio',['id_edificios'=>$i,'condominio_id'=>$i,'nombre'=>'DEMO Torre '.$i,'cantidad_pisos'=>4]);
            $insert('apartamento',['id_apartamentos'=>$i,'edificio_id'=>$i,'numero'=>'D-'.$i,'piso'=>1,'habitaciones'=>1+$i%4,'banos'=>1+$i%2,'metros_cuadrados'=>60+$i*2,'descripcion'=>'DEMO aislado','estado'=>$i%3===0?'Disponible':($sale?'Vendido':'Ocupado'),'disponible'=>$i%3===0?1:0,'tipo_operacion'=>$sale?'Venta':'Alquiler','precio_alquiler'=>$sale?null:$rent,'precio_venta'=>$sale?$amount:null]);
            $insert('persona',['id_personas'=>$i,'nombres'=>'Cliente DEMO '.$i,'apellidos'=>'Simulación','correo'=>'demo'.$i.'@example.invalid','activo'=>$i%7===0?0:1]);
            $insert('propiedad',['id_propiedades'=>$i,'apartamento_id'=>$i,'tipo_operacion'=>$sale?'VENTA':'ALQUILER','precio'=>$amount,'descripcion'=>'DEMO aislado','fecha_publicacion'=>$date,'estado'=>$i%3===0?'Disponible':'Ocupado']);
            $insert('contrato',['id_contratos'=>$i,'apartamento_id'=>$i,'persona_id'=>$i,'numero_contrato'=>'DEMO-BI-'.$i,'fecha_inicio'=>$date,'fecha_fin'=>$end,'monto_alquiler'=>$amount,'tipo_operacion'=>$sale?'Venta':'Alquiler','condiciones'=>'DEMO · no facturar ni cobrar']);
            $insert('factura',['id_facturas'=>$i,'numero_documento'=>'DEMO-BI-'.$i,'contrato_id'=>$i,'persona_id'=>$i,'periodo'=>substr($date,0,7),'fecha'=>$date,'fecha_vencimiento'=>substr($date,0,7).'-10','subtotal'=>$amount,'total'=>$amount,'monto_pagado'=>$paid,'saldo_pendiente'=>$amount-$paid,'estado'=>$paid===0?'Pendiente':($paid<$amount?'Parcial':'Pagada'),'observaciones'=>'DEMO · no fiscal']);
            // At least thirty payment rows with positive actual demo receipts; zero-balance invoices remain pending.
            if($paid>0)$insert('factura_pago',['id_pagos'=>$i,'factura_id'=>$i,'fecha_pago'=>substr($date,0,7).'-05 12:00:00','monto'=>$paid,'metodo_pago'=>['Transferencia','Efectivo','Tarjeta'][$i%3],'referencia'=>'DEMO-COBRO-'.$i]);
            $insert('ocupacion',['id_ocupaciones'=>$i,'apartamento_id'=>$i,'persona_id'=>$i,'tipo_ocupacion'=>$sale?'Propietario':'Inquilino','fecha_inicio'=>$date,'fecha_fin'=>$i%3===0?(new DateTimeImmutable($date))->modify('+5 days')->format('Y-m-d'):null,'estado'=>$i%3===0?'Finalizada':'Activa']);
            $insert('solicitud_propiedad',['id_solicitudes'=>$i,'apartamento_id'=>$i,'persona_id'=>$i,'tipo_operacion'=>$sale?'Venta':'Alquiler','estado'=>['Aprobada','Rechazada','Pendiente'][$i%3],'fecha_solicitud'=>$date.' 09:00:00','observaciones'=>'DEMO']);
            $insert('solicitud_mantenimiento',['id_solicitudes'=>$i,'apartamento_id'=>$i,'persona_id'=>$i,'descripcion'=>'DEMO · revisión '.$i,'estado'=>['Pendiente','Completada','En proceso'][$i%3],'fecha_solicitud'=>$date.' 10:00:00']);
            $insert('mantenimiento',['id_mantenimientos'=>$i,'condominio_id'=>$i,'tipo'=>'Mensual','periodo'=>substr($date,0,7),'descripcion'=>'DEMO · mantenimiento','fecha_programada'=>$date,'fecha_realizacion'=>$date,'costo'=>1200+$i*80,'estado'=>'Realizado']);
            $insert('gasto',['id'=>$i,'condominio_id'=>$i,'fecha'=>$date,'monto'=>500+$i*30,'concepto'=>'DEMO · operativo','referencia'=>'DEMO-G-'.$i]);
            $insert('inversion',['id'=>$i,'condominio_id'=>$i,'fecha'=>$date,'monto'=>1000000+$i*50000,'concepto'=>'DEMO · inversión','referencia'=>'DEMO-I-'.$i]);
            $insert('suscripcion',['id_suscripcion'=>$i,'usuario_id'=>0,'monto'=>10,'moneda'=>'USD','estado'=>['Activo','Cancelado','Vencido'][$i%3],'fecha_inicio'=>$date.' 08:00:00','fecha_proximo_pago'=>(new DateTimeImmutable($date))->modify('+1 month')->format('Y-m-d H:i:s')]);
            $insert('suscripcion_pago',['id'=>$i,'suscripcion_id'=>$i,'fecha'=>$date.' 08:00:00','monto'=>10,'moneda'=>'USD','referencia'=>'DEMO-S-'.$i]);
        }
        // Split eight receipts to reach 30+ rows without changing collected totals.
        for($i=1;$i<=30;$i++)if($i%4!==0 && $i<=11) {
            $q=$db->prepare('SELECT monto,factura_id,fecha_pago,metodo_pago FROM bi_demo_factura_pago WHERE id_pagos=?');$q->bind_param('i',$i);$q->execute();$r=$q->get_result()->fetch_assoc();
            $half=round($r['monto']/2,2);$q=$db->prepare('UPDATE bi_demo_factura_pago SET monto=monto-? WHERE id_pagos=?');$q->bind_param('di',$half,$i);$q->execute();
            $insert('factura_pago',['factura_id'=>$r['factura_id'],'fecha_pago'=>$r['fecha_pago'],'monto'=>$half,'metodo_pago'=>$r['metodo_pago'],'referencia'=>'DEMO-ABONO-'.$i]);
        }
        $db->query("INSERT INTO bi_demo_lote VALUES ('reportes-v1',NOW(),'Demostración aislada para inteligencia de negocios; no procesar cobros ni notificaciones')");
    }
    $db->commit();
} catch(Throwable $e) { $db->rollback();throw $e; }
// Additive metadata v2 also supports installations seeded by the first migration.
$db->begin_transaction();
try {
    $db->query("INSERT INTO bi_demo_usuario (id_usuarios,nombre,usuario,clave,id_rol,persona_id,activo,estado_cuenta) SELECT p.id_personas,CONCAT('Cuenta DEMO ',p.id_personas),CONCAT('bi-demo-',p.id_personas),'!INICIO_SESION_DESHABILITADO',4,p.id_personas,0,'Suspendido' FROM bi_demo_persona p LEFT JOIN bi_demo_usuario u ON u.id_usuarios=p.id_personas WHERE u.id_usuarios IS NULL");
    $db->query('UPDATE bi_demo_suscripcion SET usuario_id=id_suscripcion WHERE usuario_id=0');
    $db->query("UPDATE bi_demo_gasto SET apartamento_id=id WHERE apartamento_id IS NULL AND MOD(id,2)=1 AND referencia=CONCAT('DEMO-G-',id)");
    $db->query("UPDATE bi_demo_inversion SET apartamento_id=id WHERE apartamento_id IS NULL AND referencia=CONCAT('DEMO-I-',id)");
    $db->query("INSERT INTO bi_demo_propiedad_info (apartamento_id,tipo,disponible_desde) SELECT a.id_apartamentos,CASE MOD(a.id_apartamentos,3) WHEN 0 THEN 'Estudio' WHEN 1 THEN 'Apartamento' ELSE 'Penthouse' END,p.fecha_publicacion FROM bi_demo_apartamento a JOIN bi_demo_propiedad p ON p.apartamento_id=a.id_apartamentos LEFT JOIN bi_demo_propiedad_info i ON i.apartamento_id=a.id_apartamentos WHERE i.apartamento_id IS NULL");
    $db->commit();
}catch(Throwable $e){$db->rollback();throw $e;}
foreach([['bi_demo_usuario','bi_demo_usuario_persona','persona_id','bi_demo_persona','id_personas'],['bi_demo_suscripcion','bi_demo_sub_usuario','usuario_id','bi_demo_usuario','id_usuarios'],['bi_demo_propiedad_info','bi_demo_info_apt','apartamento_id','bi_demo_apartamento','id_apartamentos']] as [$t,$name,$col,$ref,$pk]) {
    $q=$db->prepare('SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME=? AND CONSTRAINT_NAME=?');$q->bind_param('ss',$t,$name);$q->execute();if(!(int)$q->get_result()->fetch_row()[0])$db->query("ALTER TABLE {$t} ADD CONSTRAINT {$name} FOREIGN KEY({$col}) REFERENCES {$ref}({$pk})");
}
foreach(array_merge($tables,['gasto','inversion','suscripcion_pago','propiedad_info']) as $t)echo 'bi_demo_'.$t.'='.$db->query('SELECT COUNT(*) FROM bi_demo_'.$t)->fetch_row()[0].PHP_EOL;
echo "Migración de reportes completada; datos reales intactos.\n";
