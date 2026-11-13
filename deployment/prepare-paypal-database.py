"""Back up the existing database privately before an additive, explicit migration."""
import argparse
import datetime
import hashlib
import json
import os
import subprocess
from paypal_cloud import ROOT,database

def main():
    parser=argparse.ArgumentParser();parser.add_argument('--apply',action='store_true');args=parser.parse_args()
    with database() as (db,password,private):
        with db.cursor() as c:
            c.execute('SHOW COLUMNS FROM usuario WHERE Field=%s',('id_usuarios',));column=c.fetchone()
            if column[1].lower() not in ['bigint','bigint(20)']:raise RuntimeError('Incompatible usuario ID type; no migration applied')
            c.execute('SHOW TABLES');tables=[r[0] for r in c.fetchall()]
            original={}
            for table in tables:
                c.execute('SELECT COUNT(*) FROM `'+table+'`');original[table]=c.fetchone()[0]
        if not args.apply:print('Schema compatible; migration not applied.');return
        backup=private/('viora-before-paypal-'+datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%d%H%M%S')+'.sql')
        env=os.environ.copy();env['MYSQL_PWD']=password
        with backup.open('wb') as out:
            r=subprocess.run(['C:/xampp/mysql/bin/mysqldump.exe','--host=127.0.0.1','--port=13309','--user=viora_prod','--single-transaction','--skip-lock-tables','--no-tablespaces','--skip-add-drop-table','--default-character-set=utf8mb4','viora_production'],env=env,stdout=out,stderr=subprocess.PIPE)
        if r.returncode:raise RuntimeError('Private backup failed; migration NOT applied. Details withheld to protect data.')
        data=backup.read_bytes()
        if b'Dump completed' not in data or sum(data.count(('CREATE TABLE `'+t+'`').encode()) for t in tables)!=len(tables):raise RuntimeError('Backup incomplete; migration NOT applied')
        sql=(ROOT/'Viora/conf/integraciones.sql').read_text(encoding='utf-8')
        with db.cursor() as c:
            for statement in sql.split(';'):
                if statement.strip():c.execute(statement)
            for table,count in original.items():
                c.execute('SELECT COUNT(*) FROM `'+table+'`')
                if c.fetchone()[0]!=count:raise RuntimeError('Existing counts changed; review concurrent activity. No existing rows written by this script.')
            c.execute('SHOW CREATE TABLE suscripcion_pago');definition=c.fetchone()[1]
        result={'backup_private':True,'backup_sha256':hashlib.sha256(data).hexdigest(),'backup_bytes':len(data),
                'original_tables':len(tables),'original_row_counts_unchanged':True,'migration_applied':True,
                'tables_added':[t for t in ['suscripcion_pago','paypal_evento'] if t not in tables],
                'subscription_payment_primary_key':'PRIMARY KEY (`paypal_transaction_id`)' in definition}
        (ROOT/'deployment/paypal-database-result.json').write_text(json.dumps(result,indent=2),encoding='utf-8');print(json.dumps(result,indent=2))

if __name__=='__main__':main()
