#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Pruebas basicas del sistema VIORA sin dependencias externas
"""

import json
import sys
import urllib.request
import urllib.error
import io
from datetime import datetime
from pathlib import Path

# Forzar UTF-8 en Windows
if sys.platform.startswith('win'):
    sys.stdout = io.TextIOWrapper(sys.stdout.buffer, encoding='utf-8')

BASE_URL = "https://viora-1055595302579.us-central1.run.app"

def test_viora():
    """Pruebas básicas del sitio"""
    results = {
        "timestamp": datetime.now().isoformat(),
        "url": BASE_URL,
        "tests": [],
        "success": True,
        "errors": []
    }

    tests = [
        ("health", "/healthz.php", "Health check"),
        ("login", "/login/login.php", "Página de login"),
        ("registro", "/login/registro.php", "Página de registro"),
        ("principal", "/login/principal.php", "Página principal (admin)"),
        ("catalogo", "/mantenimientos/catalogo.php", "Catálogo de propiedades"),
    ]

    for test_id, path, description in tests:
        try:
            url = BASE_URL + path
            print(f"[TEST] {description}: {url}")

            req = urllib.request.Request(url, headers={
                'User-Agent': 'VIORA-Test-Bot/1.0'
            })

            with urllib.request.urlopen(req, timeout=10) as response:
                status = response.status
                content_length = response.headers.get('content-length', 'unknown')

                result_status = "EXITOSO" if status == 200 else f"HTTP {status}"
                results["tests"].append({
                    "test_id": test_id,
                    "nombre": description,
                    "url": path,
                    "estado": result_status,
                    "http_status": status,
                    "content_length": content_length,
                    "detalles": f"Respuesta HTTP {status}"
                })

                print(f"  [PASS] {description}: HTTP {status}")

        except urllib.error.URLError as e:
            error_msg = str(e.reason) if hasattr(e, 'reason') else str(e)
            results["success"] = False
            results["errors"].append(f"{description}: URLError - {error_msg}")
            results["tests"].append({
                "test_id": test_id,
                "nombre": description,
                "estado": "FALLIDO",
                "error": error_msg
            })
            print(f"  [FAIL] {description}: {error_msg}")

        except urllib.error.HTTPError as e:
            error_msg = f"HTTP {e.code} - {e.reason}"
            # Algunos errores como 302 (redirect) son normales para ciertos endpoints
            if e.code in [302, 303, 307, 308]:
                results["tests"].append({
                    "test_id": test_id,
                    "nombre": description,
                    "estado": "EXITOSO (Redirect)",
                    "http_status": e.code,
                    "detalles": f"Redirigió HTTP {e.code}"
                })
                print(f"  [PASS] {description}: HTTP {e.code} (Redirect)")
            else:
                results["success"] = False
                results["errors"].append(f"{description}: {error_msg}")
                results["tests"].append({
                    "test_id": test_id,
                    "nombre": description,
                    "estado": "FALLIDO",
                    "http_status": e.code,
                    "error": error_msg
                })
                print(f"  [FAIL] {description}: {error_msg}")

        except Exception as e:
            error_msg = str(e)
            results["success"] = False
            results["errors"].append(f"{description}: {error_msg}")
            results["tests"].append({
                "test_id": test_id,
                "nombre": description,
                "estado": "ERROR",
                "error": error_msg
            })
            print(f"  [FAIL] {description}: {error_msg}")

    # Prueba adicional: Verificar que HTTPS esta activo
    print("\n[INFO] Verificando HTTPS...")
    if BASE_URL.startswith("https://"):
        results["https_enabled"] = True
        print("  [PASS] HTTPS esta activo")
    else:
        results["https_enabled"] = False
        results["errors"].append("Sitio no usa HTTPS")

    # Guardar resultados
    results_file = Path("deployment/test-browser-realflow-results.json")
    with open(results_file, 'w', encoding='utf-8') as f:
        json.dump(results, f, indent=2, ensure_ascii=False)

    # Resumen
    print("\n" + "="*80)
    print("RESUMEN DE PRUEBAS DEL SISTEMA VIORA")
    print("="*80)
    print(f"URL: {BASE_URL}")
    print(f"Timestamp: {results['timestamp']}")
    print(f"Estado general: {'✓ EXITOSO' if results['success'] else '✗ FALLIDO'}")
    print(f"HTTPS: {'✓ Activo' if results.get('https_enabled') else '✗ Inactivo'}")

    print(f"\nEndpoints probados: {len(results['tests'])}")
    exitosos = sum(1 for t in results['tests'] if 'EXITOSO' in t['estado'])
    print(f"  ✓ Exitosos: {exitosos}/{len(results['tests'])}")

    if results["errors"]:
        print(f"\nErrores: {len(results['errors'])}")
        for error in results["errors"]:
            print(f"  ✗ {error}")

    print(f"\nResultados guardados: {results_file}")

    # Resumen detallado de cada prueba
    print("\nDetalles de pruebas:")
    for test in results["tests"]:
        estado_symbol = "✓" if "EXITOSO" in test["estado"] else "✗"
        print(f"  {estado_symbol} {test['nombre']}: {test['estado']}")

    return results["success"]

if __name__ == "__main__":
    try:
        success = test_viora()
        sys.exit(0 if success else 1)
    except KeyboardInterrupt:
        print("\n[Interrupted by user]")
        sys.exit(130)
    except Exception as e:
        print(f"[Error] {e}", file=sys.stderr)
        sys.exit(1)
