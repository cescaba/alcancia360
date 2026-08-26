# Crear Usuarios Masivo

Plugin para crear múltiples usuarios en WordPress de forma rápida y sencilla.

## Cómo usar

1. **Activar el plugin** en WordPress
2. Ir a **Herramientas > Crear Usuarios Masivo**
3. Pegar los datos de usuarios en formato JSON
4. Hacer click en "Crear Usuarios"

## Formato JSON

```json
[
  {
    "user_login": "juan.asesor",
    "user_email": "juan@example.com",
    "first_name": "Juan",
    "last_name": "Pérez",
    "tienda_id": "456",
    "rol": "asesor",
    "password": "password123"
  },
  {
    "user_login": "maria.jefe",
    "user_email": "maria@example.com",
    "first_name": "María",
    "last_name": "González",
    "tienda_id": "457",
    "rol": "jefe_venta",
    "password": "password456"
  }
]
```

## Campos requeridos

- **user_login** (requerido): Nombre de usuario único
- **user_email** (requerido): Correo electrónico válido
- **first_name** (opcional): Nombre
- **last_name** (opcional): Apellido
- **password** (opcional): Si no se proporciona, se genera automáticamente
- **rol** (opcional): asesor, jefe_venta, gerente, administrador, etc.
- **tienda_id** (opcional): ID de la tienda/sucursal asociada

## Roles disponibles

- `asesor` - Asesor
- `jefe_venta` - Jefe de Venta
- `gerente` - Gerente
- `administrador_hyundai` - Administrador Hyundai
- `administrator` - Administrador de WordPress
- `subscriber` - Suscriptor (por defecto)

## Ejemplo de datos

Obtén las tiendas disponibles con esta query:

```sql
SELECT ID, post_title FROM wp_posts WHERE post_type = 'tienda' AND post_status = 'publish';
```

Luego construye tu JSON con los IDs de las tiendas.
