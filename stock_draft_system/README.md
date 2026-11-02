# Stock Draft System (Submodule of Slitting System 2.0)

## Overview
The **Stock Draft System** is a dedicated, fully isolated module designed for production officers to inspect month-end stock retrieved from `slitting_db`, simulate allocations, and build customer draft orders without compromising the integrity of live production tracking.

---

## Strict System Isolation & Data Integrity Guarantees

1. **Zero Changes to Parent Application**:
   - All source code, APIs, views, styles, and SQL scripts reside strictly inside `slitting_system_2.0/stock_draft_system/`.
   - Existing code, routes, packages, and files in `slitting_system_2.0/` remain 100% untouched.

2. **Read-Only Access to `slitting_db`**:
   - `slitting_db` is accessed exclusively via a SELECT-only adapter (`SlittingDbAdapter.php`).
   - Under no circumstances does this module execute `INSERT`, `UPDATE`, `DELETE`, `ALTER`, or `DROP` statements on existing `slitting_db` tables (`mother_coil`, `stock_raw_material`, etc.).

3. **Isolated Write Storage (`draft_*`)**:
   - All state changes, customer requirements, coil status overrides (`AVAILABLE`, `RESERVED`, `USED`, `OUTGOING`, `CANCELLED`), order headers, and audit trails are stored in isolated tables:
     - `draft_coils`
     - `draft_orders`
     - `draft_order_items`
     - `draft_movement_log`

---

## Directory Structure

```
slitting_system_2.0/stock_draft_system/
├── backend/
│   ├── adapters/
│   │   └── SlittingDbAdapter.php # Read-Only SELECT adapter for slitting_db
│   ├── config/
│   │   └── database.php         # PDO connection & schema auto-initializer
│   ├── models/
│   │   ├── DraftCoil.php        # Model for coil status & sync
│   │   ├── DraftOrder.php       # Model for draft orders & item allocations
│   │   └── StockMovementLog.php # Model for movement audit trail
│   └── api/
│       ├── dashboard.php        # Dashboard metrics endpoint
│       ├── month_end_stock.php  # Month-end stock inspection & sync endpoint
│       ├── stock_coils.php      # Stock coils filter & status override endpoint
│       ├── drafts.php           # Draft creation, confirm & cancel endpoint
│       └── movement_log.php     # Movement audit log endpoint
├── frontend/
│   ├── assets/
│   │   └── css/
│   │       └── style.css        # Responsive styling & status badges
│   ├── includes/
│   │   ├── header.php           # Navigation header
│   │   └── footer.php           # Layout footer
│   ├── index.php                # Dashboard view
│   ├── month_end_stock.php      # Read-only month-end stock page
│   ├── stock_coils.php          # Draft coils directory page
│   ├── create_draft.php         # Interactive 2-Panel Draft Builder
│   ├── drafts_management.php    # Active & historical draft orders page
│   └── movement_log.php         # Audit trail log page
├── database/
│   └── schema.sql               # DDL for draft_* tables
└── README.md                    # Setup & documentation
```

---

## Core Workflow & Features

1. **Dashboard**: High-level overview of total coils, available, reserved, outgoing, and month-end metrics.
2. **Month-End Stock**: View live month-end stock retrieved from `slitting_db` with one-click snapshot sync into local draft storage.
3. **Interactive 2-Panel Draft Builder**:
   - **Left Panel**: Searchable list of `AVAILABLE` coils.
   - **Right Panel**: Real-time selection workspace with live calculations for Selected vs Required Length/Weight/Area and visual badges (🔴 `NOT ENOUGH STOCK` vs 🟢 `ENOUGH STOCK`).
4. **Drafts Management**: View active drafts, confirm reservation (coil status becomes `OUTGOING`), or cancel draft (coil status reverts to `AVAILABLE`).
5. **Movement Log**: Immutable audit trail tracking every status change, timestamp, and officer ID.

---

## Access URL
Open in browser:
`http://localhost/Slitting_System_2.0/stock_draft_system/frontend/index.php`
