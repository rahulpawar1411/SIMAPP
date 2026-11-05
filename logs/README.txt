ReeferON DF — application logs (server-side, not public)

Files (daily rotation):
  app-YYYY-MM-DD.log     — all events (info, audit, warning, error, failed)
  errors-YYYY-MM-DD.log  — warnings / errors / failed only

Each line is JSON: timestamp, level, event, message, user, IP, URI, context.

Do not expose this folder on the web. .htaccess / web.config deny access.
