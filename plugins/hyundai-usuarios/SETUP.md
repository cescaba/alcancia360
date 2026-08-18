# Guía de Instalación - Hyundai Usuarios

## ⚡ Instalación Rápida

1. **Copiar** `/hyundai-usuarios` a `/wp-content/plugins/`
2. **Activar** desde WordPress Admin → Plugins
3. **Listo** ✓

---

## Qué se crea al activar

✅ **Roles:** 4 roles automáticamente
- `asesor` (Asesor)
- `jefe_de_venta` (Jefe de Venta)
- `gerente` (Gerente)
- `administrador_hyundai` (Administrador Hyundai)

✅ **Post Types:** 2 tipos personalizados
- `dealer` (Concesionarias)
- `tienda` (Sucursales)

✅ **Menú admin:** "Red de Concesionarias"

✅ **Campos personalizados:** Metadatos para dealers y tiendas

---

## 🔍 Verificación Post-Instalación

Después de activar:

1. Ve a **WordPress Admin** y verifica que aparezca **"Red de Concesionarias"** en el menú izquierdo
2. Haz clic en **Concesionarias** → Agregar nueva
3. Completa el formulario con al menos:
   - Título (ej: "Hyundai Lima Centro")
   - Selecciona Gerentes (si existen usuarios con ese rol)
4. Publica
5. Ve a **Sucursales** → Agregar nueva
6. Completa:
   - Título (ej: "Sucursal Centro")
   - **Código** (ej: "SUC-001") ← Este es el nuevo campo
   - Selecciona la Concesionaria
   - Departamento y Provincia
   - Jefe de Venta
7. Publica

## Asignar Usuarios a Sucursales

1. Ve a **WordPress Admin → Usuarios**
2. Edita un usuario con rol "asesor" o "jefe_de_venta"
3. Baja hasta la sección **"Sucursal Asociada"**
4. Selecciona una sucursal
5. Guarda cambios

## Troubleshooting

### "No aparece el menú 'Red de Concesionarias'"
→ Verifica que tu usuario tenga capability `manage_options`
→ Limpia el caché del navegador (Ctrl+Shift+R)

### "No puedo ver el campo de Sucursal en perfil de usuario"
→ El usuario debe tener rol "asesor" o "jefe_de_venta"
→ Verifica que estos roles existan

### "El select de Provincia no se actualiza"
→ Asegúrate de que JavaScript está habilitado
→ Verifica que no hay conflictos con otros plugins de JavaScript
