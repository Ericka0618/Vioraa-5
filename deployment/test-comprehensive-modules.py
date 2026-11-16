#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Pruebas automatizadas exhaustivas del sistema VIORA
Simula flujos reales de usuarios y valida cada módulo
"""

import json
import sys
import io
import time
import re
from datetime import datetime
from pathlib import Path
from http.cookiejar import CookieJar

if sys.platform.startswith('win'):
    sys.stdout = io.TextIOWrapper(sys.stdout.buffer, encoding='utf-8')

try:
    import urllib.request
    import urllib.error
    import urllib.parse
except ImportError as e:
    print(f"[ERROR] Dependencias no disponibles: {e}")
    sys.exit(1)

BASE_URL = "https://viora-1055595302579.us-central1.run.app"

class VioraComprehensiveTest:
    def __init__(self):
        self.cookie_jar = CookieJar()
        self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.cookie_jar))
        self.results = {
            "timestamp": datetime.now().isoformat(),
            "modulos": {},
            "errores_encontrados": [],
            "advertencias": [],
            "resumen": {
                "total_modulos": 0,
                "modulos_ok": 0,
                "modulos_error": 0,
                "modulos_parcial": 0
            }
        }

    def request(self, method, path, data=None, headers=None):
        """Hacer request HTTP con manejo de errores"""
        url = BASE_URL + path
        req_headers = headers or {}
        req_headers['User-Agent'] = 'VIORA-ComprehensiveTest/1.0'

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
                'success': True
            }
        except urllib.error.HTTPError as e:
            try:
                content = e.read().decode('utf-8', errors='ignore')
            except:
                content = ""
            return {
                'status': e.code,
                'content': content,
                'headers': dict(e.headers) if hasattr(e, 'headers') else {},
                'success': e.code in [200, 201, 302, 303]
            }
        except Exception as e:
            return {
                'status': None,
                'error': str(e),
                'success': False
            }

    def test_modulo(self, nombre, path, validadores=None):
        """Prueba un módulo individual"""
        print(f"\n  [PRUEBA] {nombre}")
        response = self.request('GET', path)

        resultado = {
            "path": path,
            "accesible": response['success'],
            "status": response['status'],
            "validaciones": {}
        }

        if not response['success']:
            print(f"    ERROR: HTTP {response.get('status', '?')} - {response.get('error', 'desconocido')}")
            self.results["errores_encontrados"].append(f"{nombre}: No accesible ({response.get('status')})")
            return resultado

        contenido = response['content'].lower()

        # Validaciones predeterminadas
        if 'error' in contenido or 'fatal' in contenido:
            print(f"    ADVERTENCIA: Posible error en respuesta")
            self.results["advertencias"].append(f"{nombre}: Posible error en respuesta")
            resultado["validaciones"]["sin_errores"] = False
        else:
            resultado["validaciones"]["sin_errores"] = True
            print(f"    OK: Módulo accesible")

        # Validaciones personalizadas
        if validadores:
            for val_nombre, val_func in validadores.items():
                try:
                    val_result = val_func(response['content'])
                    resultado["validaciones"][val_nombre] = val_result
                    if val_result:
                        print(f"      OK: {val_nombre}")
                    else:
                        print(f"      ERROR: {val_nombre}")
                except Exception as e:
                    print(f"      ERROR en validación {val_nombre}: {e}")

        return resultado

    def run_comprehensive_tests(self):
        """Ejecutar todas las pruebas de módulos"""
        print("\n" + "="*80)
        print("PRUEBAS EXHAUSTIVAS DEL SISTEMA VIORA")
        print("="*80)

        # Grupo 1: Autenticación
        print("\n[GRUPO 1] AUTENTICACIÓN Y SEGURIDAD")
        self.results["modulos"]["autenticacion"] = self.test_modulo(
            "Login",
            "/login/login.php",
            {
                "tiene_formulario": lambda c: 'input' in c,
                "tiene_campos_usuario": lambda c: 'usuario' in c or 'email' in c,
                "tiene_boton_envio": lambda c: 'button' in c or 'submit' in c
            }
        )

        self.results["modulos"]["registro"] = self.test_modulo(
            "Registro",
            "/login/registro.php",
            {
                "tiene_formulario": lambda c: 'input' in c,
                "tiene_campo_contraseña": lambda c: 'password' in c or 'contraseña' in c,
            }
        )

        # Grupo 2: Catálogo y Propiedades
        print("\n[GRUPO 2] PROPIEDADES Y CATÁLOGO")
        self.results["modulos"]["catalogo"] = self.test_modulo(
            "Catálogo de Propiedades",
            "/mantenimientos/catalogo.php",
            {
                "carga_contenido": lambda c: len(c) > 100
            }
        )

        self.results["modulos"]["condominio"] = self.test_modulo(
            "Condominios",
            "/mantenimientos/condominio.php"
        )

        self.results["modulos"]["edificio"] = self.test_modulo(
            "Edificios",
            "/mantenimientos/edificio.php"
        )

        self.results["modulos"]["apartamento"] = self.test_modulo(
            "Apartamentos",
            "/mantenimientos/apartamento.php"
        )

        # Grupo 3: Solicitudes
        print("\n[GRUPO 3] SOLICITUDES Y CONTRATOS")
        self.results["modulos"]["solicitudes"] = self.test_modulo(
            "Solicitudes",
            "/mantenimientos/solicitudes.php"
        )

        self.results["modulos"]["contratos"] = self.test_modulo(
            "Contratos",
            "/mantenimientos/contratos.php"
        )

        # Grupo 4: Facturación
        print("\n[GRUPO 4] FACTURACIÓN")
        self.results["modulos"]["facturacion"] = self.test_modulo(
            "Facturación",
            "/mantenimientos/facturacion.php",
            {
                "tiene_formulario": lambda c: 'input' in c,
                "tiene_tabla_o_lista": lambda c: 'table' in c or 'div' in c
            }
        )

        self.results["modulos"]["mis_facturas"] = self.test_modulo(
            "Mis Facturas",
            "/mantenimientos/mis_facturas.php"
        )

        self.results["modulos"]["consulta_facturas"] = self.test_modulo(
            "Consulta de Facturas",
            "/mantenimientos/consulta_facturas.php"
        )

        # Grupo 5: Cuentas por Cobrar
        print("\n[GRUPO 5] CUENTAS POR COBRAR")
        self.results["modulos"]["cxc_clientes"] = self.test_modulo(
            "CXC Clientes",
            "/mantenimientos/cxc_clientes.php"
        )

        self.results["modulos"]["consulta_cobros"] = self.test_modulo(
            "Consulta de Cobros",
            "/mantenimientos/consulta_cobros.php"
        )

        # Grupo 6: Clientes
        print("\n[GRUPO 6] GESTIÓN DE CLIENTES")
        self.results["modulos"]["clientes"] = self.test_modulo(
            "Clientes",
            "/mantenimientos/clientes.php"
        )

        # Grupo 7: Mantenimientos
        print("\n[GRUPO 7] MANTENIMIENTOS Y REPARACIONES")
        self.results["modulos"]["reparaciones"] = self.test_modulo(
            "Reparaciones",
            "/mantenimientos/reparaciones.php"
        )

        # Grupo 8: ECF/IMECF
        print("\n[GRUPO 8] FACTURACIÓN ELECTRÓNICA (ECF/IMECF)")
        self.results["modulos"]["consultar_ecf"] = self.test_modulo(
            "Consultar ECF",
            "/mantenimientos/consultar_ecf.php"
        )

        # Grupo 9: Chatbot
        print("\n[GRUPO 9] ASISTENTE IA (GEMINI)")
        self.results["modulos"]["chatbot_widget"] = self.test_modulo(
            "Widget Chatbot",
            "/mantenimientos/chatbot_widget.php"
        )

        # Grupo 10: Health Check
        print("\n[GRUPO 10] SISTEMA Y UTILIDADES")
        self.results["modulos"]["health"] = self.test_modulo(
            "Health Check",
            "/healthz.php"
        )

        # Calcular resumen
        self.results["resumen"]["total_modulos"] = len(self.results["modulos"])
        self.results["resumen"]["modulos_ok"] = sum(1 for m in self.results["modulos"].values() if m["accesible"])
        self.results["resumen"]["modulos_error"] = sum(1 for m in self.results["modulos"].values() if not m["accesible"])

        return self.results

    def print_summary(self):
        """Imprimir resumen de resultados"""
        print("\n" + "="*80)
        print("RESUMEN DE PRUEBAS EXHAUSTIVAS")
        print("="*80)

        print(f"\nModulos probados: {self.results['resumen']['total_modulos']}")
        print(f"  OK: {self.results['resumen']['modulos_ok']}")
        print(f"  ERROR: {self.results['resumen']['modulos_error']}")

        if self.results["errores_encontrados"]:
            print(f"\nErrores encontrados: {len(self.results['errores_encontrados'])}")
            for error in self.results["errores_encontrados"]:
                print(f"  - {error}")

        if self.results["advertencias"]:
            print(f"\nAdvertencias: {len(self.results['advertencias'])}")
            for adv in self.results["advertencias"]:
                print(f"  - {adv}")

        # Guardar resultados
        results_file = Path("deployment/test-comprehensive-results.json")
        with open(results_file, 'w', encoding='utf-8') as f:
            json.dump(self.results, f, indent=2, ensure_ascii=False)

        print(f"\nResultados guardados: {results_file}")

def main():
    try:
        tester = VioraComprehensiveTest()
        tester.run_comprehensive_tests()
        tester.print_summary()
        return 0
    except Exception as e:
        print(f"[Error] {e}", file=sys.stderr)
        return 1

if __name__ == "__main__":
    sys.exit(main())
