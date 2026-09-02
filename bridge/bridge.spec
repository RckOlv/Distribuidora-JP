# PyInstaller spec para empaquetar el print bridge en Windows.
#
# Uso:  pyinstaller bridge.spec
# Genera dist/VerduleriaPrintBridge/ con el ejecutable.
# Dependencia adicional solo para el modo servicio en Windows: pywin32.

block_cipher = None

a = Analysis(
    ["__main__.py"],
    pathex=["."],
    binaries=[],
    datas=[("config.example.json", ".")],
    hiddenimports=[],
    hookspath=[],
    runtime_hooks=[],
    excludes=[
        "tkinter",
        "unittest",
        "pydoc",
        "doctest",
        "pdb",
    ],
    win_no_prefer_redirects=False,
    win_private_assemblies=False,
    cipher=block_cipher,
    noarchive=False,
)

pyz = PYZ(a.pure, a.zipped_data, cipher=block_cipher)

exe = EXE(
    pyz,
    a.scripts,
    [],
    exclude_binaries=True,
    name="VerduleriaPrintBridge",
    debug=False,
    bootloader_ignore_signals=False,
    strip=False,
    upx=True,
    console=True,
)

coll = COLLECT(
    exe,
    a.binaries,
    a.zipfiles,
    a.datas,
    strip=False,
    upx=True,
    upx_exclude=[],
    name="VerduleriaPrintBridge",
)