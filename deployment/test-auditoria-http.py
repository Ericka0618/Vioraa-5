"""Exercise isolated local VIORA instances with the fixtures from test-auditoria.php."""
import concurrent.futures
import http.cookiejar
import io
import json
import os
import pathlib
import re
import sys
import urllib.error
import urllib.parse
import urllib.request
import xml.etree.ElementTree as ET
import zipfile

fixture = json.loads(pathlib.Path(sys.argv[1]).read_text(encoding="utf-8"))
ports = [int(arg) for arg in sys.argv[2:]] or [8131, 8132]
panel = "/configuracion/auditoria.php"


class KeepRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None


def browser():
    return urllib.request.build_opener(KeepRedirect(), urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))


def request(client, path, form=None, *, port=ports[0], body=None, headers=None):
    if form is not None:
        body = urllib.parse.urlencode(form).encode()
    headers = {**(headers or {})}
    if os.getenv("VIORA_QA_ID_TOKEN"):
        headers["Authorization"] = "Bearer " + os.environ["VIORA_QA_ID_TOKEN"]
    base = os.getenv("VIORA_QA_BASE_URL") or f"http://127.0.0.1:{port}"
    try:
        resp = client.open(urllib.request.Request(base + path, body, headers), timeout=45)
    except urllib.error.HTTPError as error:
        resp = error
    with resp:
        return resp.status, resp.headers, resp.read()


def login(client, username, password):
    return request(client, "/login/procesar_login.php", {"username": username, "password": password})


def token(body, pattern):
    found = re.search(pattern, body)
    assert found, "No se encontró el token en la página"
    return found.group(1).decode()


anonymous = browser()
assert request(anonymous, panel)[0] == 302
assert "invalid_credentials" in login(anonymous, fixture["admin"], "incorrecta")[1]["Location"]
client = browser()
assert "catalogo.php" in login(client, fixture["cliente"], fixture["password"])[1]["Location"]
assert request(client, "/mantenimientos/catalogo.php")[0] == 200
assert request(client, panel)[0] == 403
admin = browser()
assert "principal.php" in login(admin, fixture["admin"], fixture["password"])[1]["Location"]
print("OK autenticación real, credenciales rechazadas y acceso administrativo")

status, _, html = request(admin, panel + "?q=" + fixture["admin"] + "&orden=modulo&direccion=asc")
assert status == 200 and b"Historial de operaciones" in html and b"Usuarios activos" in html
assert b"session_start()" not in html
csrf = token(html, rb'name="csrf" value="([a-f0-9]+)"')
status, _, client_activity = request(admin, panel + "?" + urllib.parse.urlencode({"rol":"Cliente", "modulo":"Propiedades"}))
assert status == 200 and b"GET catalogo.php" in client_activity and b"Cliente QA" in client_activity
assert request(admin, panel, {"operacion": "pdf", "csrf": "invalid"})[0] == 403
status, _, ai_no_secret = request(admin, panel, {"operacion": "ia", "csrf": csrf, "pregunta": "¿Cuántos intentos fallidos hubo hoy?"})
if os.getenv("VIORA_QA_EXPECT_GEMINI"):
    assert status == 200 and b"Gemini aplic" in ai_no_secret
else:
    assert status == 502 and "No se pudo completar la consulta con Gemini".encode() in ai_no_secret
status, _, html = request(admin, panel + "?vista=detalle&id=" + str(fixture["detalle"]))
assert status == 200 and b"15000.00" in html and b"18000.00" in html and b"Huella verificada" in html
status, _, html = request(admin, panel + "?vista=seguimiento&entidad=contrato&referencia=" + str(fixture["contrato"]))
assert status == 200 and b"factura_pago" in html and b"factura_ecf" in html and b"factura_pdf.php?id=" in html
invoice_pdf = "/mantenimientos/factura_pdf.php?id=" + str(fixture["factura"])
assert request(anonymous, invoice_pdf)[0] == 302
status, _, original = request(admin, invoice_pdf)
assert status == 200 and original.startswith(b"%PDF-")
print("OK indicadores, filtros, orden, detalles, huella, documentos y CSRF")

filtered = panel + "?reporte=12&factura=" + str(fixture["factura"])
status, _, pdf = request(admin, filtered, {"operacion": "pdf", "csrf": csrf})
assert status == 200 and pdf.startswith(b"%PDF-")
status, _, xlsx = request(admin, filtered, {"operacion": "xlsx", "csrf": csrf})
assert status == 200
with zipfile.ZipFile(io.BytesIO(xlsx)) as workbook:
    assert workbook.testzip() is None
    sheet = ET.fromstring(workbook.read("xl/worksheets/sheet1.xml"))
    ns = {"s": "http://schemas.openxmlformats.org/spreadsheetml/2006/main"}
    rows = sheet.findall(".//s:sheetData/s:row", ns)
    assert len(rows) >= 6
    assert all("factura" in " ".join(t.text or "" for t in row.findall(".//s:t", ns)).lower() for row in rows[2:])
print("OK descargas HTTP de PDF y XLSX filtrados")

if "solicitud" in fixture:
    individual = "/mantenimientos/trazabilidad.php?"
    def trace(entity, key):
        return individual + urllib.parse.urlencode({"entidad": entity, "referencia": fixture[key]})

    assert request(anonymous, trace("factura", "factura"))[0] == 401
    assert request(client, trace("persona", "persona"))[0] == 404
    status, _, customer_invoice = request(client, trace("factura", "factura"))
    assert status == 200 and b"Personal autorizado" in customer_invoice
    assert b"Ver referencias y detalle" not in customer_invoice
    assert request(client, trace("factura", "factura"), {"formato": "pdf", "csrf": "invalid"})[0] == 403
    trace_csrf = token(customer_invoice, rb'name="csrf" value="([a-f0-9]+)"')
    status, _, pdf = request(client, trace("factura", "factura"), {"formato": "pdf", "csrf": trace_csrf})
    assert status == 200 and pdf.startswith(b"%PDF-")
    status, _, xlsx = request(client, trace("factura", "factura"), {"formato": "xlsx", "csrf": trace_csrf})
    assert status == 200 and zipfile.is_zipfile(io.BytesIO(xlsx))
    assert request(client, trace("suscripcion", "suscripcion"))[0] == 200
    assert request(client, trace("solicitud_mantenimiento", "reparacion"))[0] == 200
    status, _, timeline = request(admin, trace("solicitud_propiedad", "solicitud"))
    assert status == 200 and "Aplicación de pago".encode() in timeline
    status, _, rejected = request(admin, trace("solicitud_propiedad", "rechazada"))
    assert status == 200 and b"Rechazada" in rejected and b"factura_pdf.php" not in rejected
    chat_csrf = token(timeline, rb'data-csrf="([a-f0-9]+)"')
    for message, report_expected in [
        (f"¿Quién modificó el precio del apartamento #{fixture['apartamento']}?", False),
        ("Genera un reporte de trazabilidad de la solicitud seleccionada.", True),
    ]:
        data = {"mensaje": message, "pagina": {"registro": {"entidad": "solicitud_propiedad", "referencia": str(fixture["solicitud"])}}}
        status, _, answer = request(admin, "/mantenimientos/chatbot_api.php", body=json.dumps(data).encode(),
                                    headers={"Content-Type": "application/json", "X-CSRF-Token": chat_csrf})
        result = json.loads(answer)
        assert status == 200 and "evento #" in result["respuesta"], result
        assert ("trazabilidad" in result) == report_expected
    print("OK trazabilidad individual, permisos, aislamiento, exportación y chat general")

status, _, billing = request(admin, "/mantenimientos/facturacion.php")
assert status == 200
billing_csrf = token(billing, rb'name="ecf-csrf" content="([a-f0-9]+)"')
second_admin = browser()
assert login(second_admin, fixture["admin"], fixture["password"])[0] == 302
status, _, second_billing = request(second_admin, "/mantenimientos/facturacion.php", port=ports[-1])
assert status == 200
second_csrf = token(second_billing, rb'name="ecf-csrf" content="([a-f0-9]+)"')
assert second_csrf != billing_csrf
payment = {"factura_id": fixture["factura_web"], "monto": "18000.00", "metodo_pago": "Efectivo"}

def pay(actor):
    client, port, billing_token = actor
    headers = {"X-CSRF-Token": billing_token, "Content-Type": "application/json"}
    status, _, body = request(client, "/mantenimientos/registrar_pago.php", port=port, body=json.dumps(payment).encode(), headers=headers)
    return status, json.loads(body)["success"]

with concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool:
    results = list(pool.map(pay, [(admin, ports[0], billing_csrf), (second_admin, ports[-1], second_csrf)]))
assert sorted(result[1] for result in results) == [False, True], results
status, _, timeline = request(admin, panel + "?vista=seguimiento&entidad=factura&referencia=" + str(fixture["factura_web"]))
assert status == 200 and b"registrar_pago.php" in timeline and b"Fallida" in timeline
print("OK pago concurrente en dos procesos PHP, cambio confirmado y rechazo auditado")

assert request(admin, "/login/logout.php")[0] == 302
assert request(admin, panel)[0] == 302
observer = browser()
assert login(observer, fixture["admin"], fixture["password"])[0] == 302
for action, appearance in (("Inicio de sesión", "Intento sin sesión autorizada"),
                           ("Cierre de sesión", "Sesión cerrada"), ("Exportar PDF", "Exportar PDF"),
                           ("Exportar Excel", "Exportar Excel"), ("Acceso denegado", "Acceso denegado")):
    status, _, history = request(observer, panel + "?" + urllib.parse.urlencode({"accion": action}))
    assert status == 200 and appearance.encode() in history
print("OK cierre de sesión, cambios de sesión y auditoría de la propia auditoría")
