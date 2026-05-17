/**
 * Run all MySQL migrations against DB_* from .env (e.g. Railway).
 *
 * Usage: npm run migrate
 */
require('dotenv').config();
const fs = require('fs');
const path = require('path');
const mysql = require('mysql2/promise');
const bcrypt = require('bcryptjs');
const { generateObjectId } = require('../src/utils/objectId');

const schemaPath = path.join(__dirname, 'schema.mysql.hosting.sql');

async function getConnection() {
  const port = parseInt(process.env.DB_PORT || '3306', 10);
  return mysql.createConnection({
    host: process.env.DB_HOST || 'localhost',
    port: Number.isFinite(port) ? port : 3306,
    user: process.env.DB_USER,
    password: process.env.DB_PASSWORD,
    database: process.env.DB_NAME,
    multipleStatements: true
  });
}

async function ignoreDuplicate(err) {
  const code = err?.code || '';
  const msg = String(err?.message || '');
  if (
    code === 'ER_DUP_FIELDNAME' ||
    code === 'ER_DUP_KEYNAME' ||
    code === 'ER_CANT_DROP_FIELD_OR_KEY' ||
    msg.includes('Duplicate column') ||
    msg.includes('Duplicate key name') ||
    msg.includes("Can't DROP") ||
    msg.includes('check that column/key exists') ||
    msg.includes('Unknown column')
  ) {
    return;
  }
  throw err;
}

async function runSchema(conn) {
  const sql = fs.readFileSync(schemaPath, 'utf8');
  console.log('Applying schema from schema.mysql.hosting.sql ...');
  await conn.query(sql);
  console.log('Core tables ready (users, admins, token_sessions, whatsapp_clients, campaigns, contacts, message_logs)');
}

async function patchUsersColumns(conn) {
  const alters = [
    'ALTER TABLE users ADD COLUMN message_balance INT NOT NULL DEFAULT 0',
    'ALTER TABLE users ADD COLUMN auth_token TEXT NULL',
    'ALTER TABLE users ADD COLUMN api_token TEXT NULL',
    'ALTER TABLE users ADD COLUMN api_token_created_at DATETIME NULL'
  ];
  for (const sql of alters) {
    try {
      await conn.query(sql);
      console.log('Applied:', sql.split('ADD COLUMN ')[1]);
    } catch (err) {
      await ignoreDuplicate(err);
    }
  }
}

async function seedDefaultAdmin(conn) {
  const [existing] = await conn.query(
    `SELECT email, password FROM users WHERE role = 'admin' LIMIT 1`
  );
  if (existing.length > 0) {
    const row = existing[0];
    await conn.query(
      `INSERT INTO admins (name, email, password, is_active, created_at)
       VALUES (?, ?, ?, 1, NOW())
       ON DUPLICATE KEY UPDATE password = VALUES(password), is_active = 1`,
      ['Admin', row.email, row.password]
    );
    console.log('Admin already exists; synced to admins table');
    return;
  }

  const userId = generateObjectId();
  const hashedPassword = await bcrypt.hash('admin123', 10);
  await conn.query(
    `INSERT INTO users (id, name, email, password, role, is_active, message_balance, created_at)
     VALUES (?, ?, ?, ?, ?, ?, ?, NOW())`,
    [userId, 'Admin', 'admin@admin.com', hashedPassword, 'admin', 1, 999999]
  );
  await conn.query(
    `INSERT INTO admins (name, email, password, is_active, created_at)
     VALUES (?, ?, ?, 1, NOW())
     ON DUPLICATE KEY UPDATE password = VALUES(password), is_active = 1`,
    ['Admin', 'admin@admin.com', hashedPassword]
  );
  console.log('Created default admin: admin@admin.com / admin123 (change after first login)');
}

async function listTables(conn) {
  const [rows] = await conn.query('SHOW TABLES');
  const key = Object.keys(rows[0] || {})[0] || 'Tables_in_db';
  return rows.map((r) => r[key]);
}

async function migrate() {
  if (!process.env.DB_HOST || !process.env.DB_USER || !process.env.DB_NAME) {
    console.error('Missing DB_HOST, DB_USER, or DB_NAME in .env');
    process.exit(1);
  }

  let conn;
  try {
    conn = await getConnection();
    await conn.query('SELECT 1');
    console.log(`Connected to MySQL (${process.env.DB_HOST}:${process.env.DB_PORT || 3306}/${process.env.DB_NAME})`);

    await runSchema(conn);
    await patchUsersColumns(conn);
    await seedDefaultAdmin(conn);

    const tables = await listTables(conn);
    console.log('\nTables in database:', tables.join(', '));
    console.log('\nMigration complete.');
  } catch (err) {
    console.error('Migration failed:', err.message || err);
    process.exit(1);
  } finally {
    if (conn) await conn.end();
  }
}

migrate();
