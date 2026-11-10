import pathlib,sys,zipfile,subprocess,datetime,shutil,tempfile
mode=sys.argv[1]
assert mode in ('qa','production')
root=pathlib.Path('/var/lib/viora-qa-20261110/app' if mode=='qa' else '/var/www/vioraa-5')
backup=pathlib.Path('/var/backups/viora')/('functional-'+mode+'-'+datetime.datetime.now().strftime('%Y%m%d%H%M%S'))
backup.mkdir(mode=0o700)
with zipfile.ZipFile('/tmp/viora-functional-fixes.zip') as z, tempfile.TemporaryDirectory() as tmp:
    for name in z.namelist():
        assert name.startswith('Viora/') and '..' not in pathlib.PurePosixPath(name).parts
        stage=pathlib.Path(tmp)/pathlib.Path(name).name
        stage.write_bytes(z.read(name))
        subprocess.run(['php','-l',str(stage)],check=True)
    for name in z.namelist():
        target=root/name
        if target.exists():shutil.copy2(target,backup/target.name)
        target.write_bytes(z.read(name))
print('Applied to',mode,'backup:',backup)
