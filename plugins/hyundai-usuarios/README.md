# Hyundai Usuarios

Plugin para gestionar la estructura de Concesionarias, Sucursales y la asociación de usuarios (Asesores y Jefes de Venta) en la intranet Hyundai.

**Características principales:**
- ✓ Crea 4 roles automáticamente al activarse
- ✓ Gestiona Concesionarias (Dealers) y Sucursales (Tiendas)
- ✓ Asocia usuarios a sucursales
- ✓ Menú unificado en admin

## Características

### Roles Creados Automáticamente

#### 1. Dealer (Concesionaria)
- **Descripción:** Representa las concesionarias Hyundai
- **Campos:**
  - Título (nombre de la concesionaria)
  - Gerentes asociados (multi-select de usuarios con rol "gerente")
  - Imagen destacada (thumbnail)

#### 2. Tienda (Sucursal)
- **Descripción:** Representa las sucursales asociadas a cada concesionaria
- **Campos:**
  - Título (nombre de la sucursal)
  - **Código** (string - identificador único de la sucursal, ej: SUC-001)
  - Concesionaria asociada (select de dealers)
  - Departamento (select dinámico)
  - Provincia (select dependiente del departamento)
  - Jefe de Venta (select de usuarios con rol "jefe_de_venta")

### Roles Creados Automáticamente

Al activar el plugin, se crean automáticamente 4 roles:

| Rol | Slug | Usuarios Típicos | Capabilities |
|-----|------|------------------|--------------|
| **Asesor** | `asesor` | 300+ | Solo lectura |
| **Jefe de Venta** | `jefe_de_venta` | 80+ | Solo lectura (supervisor) |
| **Gerente** | `gerente` | ~20 | Editar dealers/tiendas |
| **Administrador Hyundai** | `administrador_hyundai` | ~10 | Gestionar todo + usuarios |

**Capacidades por rol:**

- **Asesor:** `read` (solo lectura)
- **Jefe de Venta:** `read` (solo lectura con datos de supervisión)
- **Gerente:** `read`, `edit_posts`, `publish_posts`, `delete_posts`
- **Administrador Hyundai:** Todas menos `manage_options` (settings globales)

### Asociación de Usuarios

Los usuarios con rol "asesor" o "jefe_de_venta" pueden asociarse a una sucursal desde su perfil:

- Campo adicional en "Editar Perfil" o "Mi Perfil"
- Selector de sucursal (muestra código si existe)
- Columna en lista de usuarios mostrando sucursal asociada

### Estructura de Menú

Se crea un menú unificado bajo **"Red de Concesionarias"** con dos submenús:
- Concesionarias (lista y edición de dealers)
- Sucursales (lista y edición de tiendas)

### Columnas Personalizadas

#### Lista de Dealers
- Gerentes asociados

#### Lista de Tiendas
- Código
- Concesionaria (con enlace)
- Departamento
- Provincia
- Jefe de Venta

## Ubicación de Datos

Todos los datos se guardan en metadatos de posts y usuarios:

### Post Meta (Dealers)
- `_gerentes_asociados` - Array de IDs de usuarios gerente

### Post Meta (Tiendas)
- `_codigo` - String con el código de la sucursal
- `_dealer_id` - ID del dealer asociado
- `_departamento` - Nombre del departamento
- `_provincia` - Nombre de la provincia
- `_jefe_venta` - ID del usuario jefe de venta

### User Meta
- `_tienda_asociada` - ID de la tienda asociada

## Instalación

1. Colocar la carpeta `hyundai-usuarios` en `/wp-content/plugins/`
2. Activar desde WordPress Admin → Plugins
3. **Automáticamente se crearán:**
   - Los 4 roles (asesor, jefe_de_venta, gerente, administrador_hyundai)
   - Los 2 post types (dealer, tienda)
   - El menú "Red de Concesionarias"

## ⚠️ Migración de Usuarios Existentes

Si ya tienes usuarios con el rol `customer` (Asesor anterior):

```sql
-- Actualizar usuarios de customer a asesor
UPDATE wp_usermeta 
SET meta_value = 'a:1:{s:6:"asesor";b:1;}'
WHERE meta_key = 'wp_capabilities' 
AND meta_value LIKE '%customer%';
```

O usar WordPress Admin para cambiar roles manualmente.

## Notas Técnicas

- Usa WordPress Rest API (`show_in_rest: true`)
- Implementa nonces para seguridad
- Select dinámico departamento/provincia con JavaScript vanilla
- Filtros y acciones estándar de WordPress
