# Estructura de carpetas — Plugin penca-wc2026

```
penca-wc2026/
│
├── penca-wc2026.php                    # Archivo principal del plugin
│
├── includes/                           # Clases core (no son módulos)
│   ├── class-installer.php             # Creación y migración de tablas BD
│   └── class-helpers.php              # Utilidades globales (timezone, logs, email)
│
├── modules/                            # 6 módulos independientes
│   ├── api-sync/
│   │   └── class-api-sync.php         # Cron + dual API + fallback + logs
│   │
│   ├── match-engine/
│   │   └── class-match-engine.php     # Fixture, kickoff, cierre de pronósticos
│   │
│   ├── prediction-engine/
│   │   └── class-prediction-engine.php # CRUD pronósticos + triple validación
│   │
│   ├── score-engine/
│   │   └── class-score-engine.php     # Lógica de puntos 8/5/3/0
│   │
│   ├── ranking-engine/
│   │   └── class-ranking-engine.php   # Tabla pública + perfil de usuario
│   │
│   └── access-codes/
│       └── class-access-codes.php     # Registro con código único MUN26-XXXX-XXXX
│
├── admin/                              # Panel de administración
│   ├── class-admin.php                # Clase principal del panel admin
│   ├── views/                         # Templates PHP del admin
│   │   ├── dashboard.php             # Estado general, API status, alertas
│   │   ├── codes.php                 # Generar, exportar CSV, ver uso
│   │   ├── override.php             # Edición manual de resultados
│   │   └── logs.php                 # Visor de logs en tiempo real
│   └── assets/
│       ├── css/
│       │   └── admin.css             # Estilos del panel admin
│       └── js/
│           └── admin.js              # Scripts del panel admin
│
├── public/                            # Frontend público
│   ├── class-public.php              # Clase principal del frontend
│   ├── views/                        # Templates PHP del frontend
│   │   ├── ranking.php              # Tabla de ranking pública
│   │   ├── user-profile.php         # Perfil de usuario con historial
│   │   ├── my-predictions.php       # Pronósticos del usuario logueado
│   │   └── register.php             # Formulario de registro con código
│   └── assets/
│       ├── css/
│       │   └── public.css           # Estilos del frontend (mobile first)
│       └── js/
│           └── public.js            # Scripts del frontend
│
└── languages/                         # Internacionalización (i18n)
    └── penca-wc2026.pot              # Template de traducción
```
