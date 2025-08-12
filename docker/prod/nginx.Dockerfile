# syntax=docker/dockerfile:1.7

# Build static assets with Node
ARG NODE_VERSION=20
FROM node:${NODE_VERSION}-alpine AS assets
WORKDIR /app
COPY laravel/package.json laravel/package-lock.json ./
RUN npm ci
COPY laravel/ ./
RUN npm run build

# Final Nginx image
FROM nginx:stable-alpine
WORKDIR /var/www/html

# Copy public assets (favicon/robots etc.)
COPY laravel/public/ /var/www/html/public/
# Copy built assets from Node stage
COPY --from=assets /app/public/build /var/www/html/public/build

# Nginx conf
COPY docker/prod/nginx.conf /etc/nginx/nginx.conf

# Healthcheck
RUN apk add --no-cache wget
HEALTHCHECK --interval=10s --timeout=5s --retries=10 CMD wget -qO- http://localhost:8080/healthz || exit 1