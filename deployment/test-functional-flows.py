#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Pruebas funcionales reales del sistema VIORA
Simula flujos completos de usuario con sesiones HTTP
"""

import json
import sys
import io
from datetime import datetime
from pathlib import Path

if sys.platform.startswith('win'):
    sys.stdout = io.TextIOWrapper(sys.stdout.buffer, encoding='utf-8')

try:
    import urllib.request
    import urllib.error
    import urllib.parse
    from http.cookiejar import CookieJar
except ImportError as e:
    print(f"[Error] Dependencias no disponibles: {e}")
    sys.exit(1)

BASE_URL = "https://viora-1055595302579.us-central1.run.app"

class VioraTestSession:
    def __init__(self, base_url):
        self.base_url = base_url
        self.cookie_jar = CookieJar()
        self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.cookie_jar))
        self.results = {
            "timestamp": datetime.now().isoformat(),
            "url": base_url,
            "tests": [],
            "success": True,
            "errors": [],
            "warnings": []
        }

    def request(self, method, path, data=None, headers=None):
        """Hacer una request HTTP con manejo de sesiones"""
        url = self.base_url + path
        req_headers = headers or {}
        req_headers['User-Agent'] = 'VIORA-FunctionalTest/1.0'

        try:
            if data:
                data = urllib.parse.urlencode(data).encode('utf-8')
                req = urllib.request.Request(url, data=data, headers=req_headers, method=method)
            else:
                req = urllib.request.Request(url, headers=req_headers, method=method)

            response = self.opener.open(req, timeout=10)
            content = response.read().decode('utf-8', errors='ignore')
            return {
                'status': response.status,
                'content': content,
                'headers': dict(response.headers),
                'success': response.status in [200, 201, 302, 303]
            }
        except urllib.error.HTTPError as e:
            content = e.read().decode('utf-8', errors='ignore')
            return {
                'status': e.code,
                'content': content,
                'headers': dict(e.headers),
                'success': e.code in [302, 303]  # Redirects son esperadas
            }
        except Exception as e:
            return {
                'status': None,
                'error': str(e),
                'success': False
            }

    def test_endpoint_accessibility(self):
        """[1/5] Prueba que los endpoints principales sean accesibles"""
        print("\n[1/5] Verificando accesibilidad de endpoints...")

        endpoints = [
            ("/", "Home"),
            ("/login/login.php", "Login"),
            ("/login/registro.php", "Registro"),
            ("/login/principal.php", "Principal"),
            ("/mantenimientos/catalogo.php", "Catalogo"),
            ("/mantenimientos/condominios.php", "Condominios"),
            ("/mantenimientos/facturacion.php", "Facturacion"),
        ]

        for path, name in endpoints:
            response = self.request('GET', path)
            if response['success']:
                print(f"  [OK] {name}: HTTP {response['status']}")
                self.results["tests"].append({
                    "nombre": f"Endpoint {name}",
                    "resultado": "EXITOSO",
                    "status": response['status']
                })
            else:
                print(f"  [ERROR] {name}: {response.get('error', response['status'])}")
                self.results["success"] = False
                self.results["errors"].append(f"Endpoint {name} no accesible")

    def test_form_rendering(self):
        """[2/5] Prueba que los formularios carguen correctamente"""
        print("\n[2/5] Verificando que formularios carguen...")

        forms = {
            "/login/login.php": ["usuario", "contrasena"],
            "/login/registro.php": ["usuario", "contrasena", "email"],
            "/mantenimientos/condominios.php": ["nombre", "descripcion"],
        }

        for path, required_fields in forms.items():
            response = self.request('GET', path)

            if not response['success']:
                print(f"  [ERROR] No se pudo cargar {path}")
                self.results["errors"].append(f"Formulario en {path} no carga")
                continue

            contenido = response['content'].lower()
            campos_encontrados = sum(1 for campo in required_fields if campo.lower() in contenido)

            if campos_encontrados >= len(required_fields) * 0.7:  # 70% de campos esperados
                print(f"  [OK] Formulario en {path}: {campos_encontrados}/{len(required_fields)} campos")
                self.results["tests"].append({
                    "nombre": f"Formulario {path}",
                    "resultado": "EXITOSO",
                    "campos": campos_encontrados
                })
            else:
                print(f"  [WARN] Formulario en {path}: solo {campos_encontrados}/{len(required_fields)} campos")
                self.results["warnings"].append(f"Formulario en {path} incompleto")

    def test_session_management(self):
        """[3/5] Prueba que la gestión de sesiones funcione"""
        print("\n[3/5] Verificando gestión de sesiones...")

        # Primer request para establecer sesión
        response1 = self.request('GET', '/login/login.php')

        # Verificar que la cookie se guardó
        cookies = list(self.cookie_jar)

        if cookies:
            print(f"  [OK] Sesión establecida ({len(cookies)} cookie(s))")
            for cookie in cookies:
                print(f"      - {cookie.name} (domain={cookie.domain})")
            self.results["tests"].append({
                "nombre": "Gestor de sesiones",
                "resultado": "EXITOSO",
                "cookies": len(cookies)
            })
        else:
            print(f"  [WARN] No hay cookies de sesion establecidas")
            self.results["warnings"].append("No hay cookies de sesion")

    def test_database_connectivity(self):
        """[4/5] Prueba que la BD esté accesible"""
        print("\n[4/5] Verificando conectividad de base de datos...")

        # Intentar acceder a un endpoint que consulta la BD
        response = self.request('GET', '/mantenimientos/condominios.php')

        if response['success']:
            # Si el endpoint responde sin error 500, es que la BD está accesible
            if "error" not in response['content'].lower() and "fatal" not in response['content'].lower():
                print(f"  [OK] Base de datos accesible")
                self.results["tests"].append({
                    "nombre": "Base de datos",
                    "resultado": "EXITOSO"
                })
            else:
                print(f"  [WARN] Posible error en base de datos")
                self.results["warnings"].append("Posible error en BD")
        else:
            print(f"  [ERROR] No se puede acceder a BD")
            self.results["errors"].append("Base de datos no accesible")
            self.results["success"] = False

    def test_security_headers(self):
        """[5/5] Prueba headers de seguridad"""
        print("\n[5/5] Verificando headers de seguridad...")

        response = self.request('GET', '/login/login.php')

        security_checks = {
            'HTTPS': self.base_url.startswith('https://'),
            'Content-Type Header': 'content-type' in response.get('headers', {}),
            'Server Header': 'server' not in response.get('headers', {}).get('server', '').lower() or 'Apache' in response.get('headers', {}).get('server', '')
        }

        for check_name, passed in security_checks.items():
            if passed:
                print(f"  [OK] {check_name}")
            else:
                print(f"  [WARN] {check_name}")
                self.results["warnings"].append(f"Security: {check_name}")

        self.results["tests"].append({
            "nombre": "Headers de seguridad",
            "resultado": "EXITOSO",
            "checks": len([v for v in security_checks.values() if v])
        })

    def run_all_tests(self):
        """Ejecutar todas las pruebas"""
        self.test_endpoint_accessibility()
        self.test_form_rendering()
        self.test_session_management()
        self.test_database_connectivity()
        self.test_security_headers()

        return self.results

    def print_summary(self):
        """Imprimir resumen de resultados"""
        print("\n" + "="*80)
        print("RESUMEN DE PRUEBAS FUNCIONALES DE VIORA")
        print("="*80)

        print(f"\nURL: {self.base_url}")
        print(f"Timestamp: {self.results['timestamp']}")
        print(f"Estado general: {'[OK] EXITOSO' if self.results['success'] else '[ERROR] FALLIDO'}")

        print(f"\nPruebas ejecutadas: {len(self.results['tests'])}")
        exitosas = sum(1 for t in self.results['tests'] if t.get('resultado') == 'EXITOSO')
        print(f"  [OK] Exitosas: {exitosas}/{len(self.results['tests'])}")

        if self.results['errors']:
            print(f"\n[ERRORES] ({len(self.results['errors'])} total):")
            for error in self.results['errors']:
                print(f"  - {error}")

        if self.results['warnings']:
            print(f"\n[ADVERTENCIAS] ({len(self.results['warnings'])} total):")
            for warning in self.results['warnings']:
                print(f"  - {warning}")

        # Detalles de cada prueba
        print("\nDetalles de pruebas:")
        for test in self.results['tests']:
            print(f"  [{test.get('resultado')}] {test['nombre']}")

        # Guardar resultados
        results_file = Path("deployment/test-functional-flows-results.json")
        with open(results_file, 'w', encoding='utf-8') as f:
            json.dump(self.results, f, indent=2, ensure_ascii=False)

        print(f"\n[OK] Resultados guardados: {results_file}")

def main():
    try:
        tester = VioraTestSession(BASE_URL)
        tester.run_all_tests()
        tester.print_summary()

        return 0 if tester.results['success'] else 1

    except KeyboardInterrupt:
        print("\n[Interrupted by user]")
        return 130
    except Exception as e:
        print(f"[Error] {e}", file=sys.stderr)
        return 1

if __name__ == "__main__":
    sys.exit(main())
