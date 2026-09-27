# 빌드 context: apps/web, 추가 context "nginx": infra/nginx
FROM node:24-alpine AS web
WORKDIR /web
COPY package.json package-lock.json ./
RUN npm ci
COPY . .
RUN npm run build-only

FROM nginx:1.29-alpine
COPY --from=nginx default.conf /etc/nginx/conf.d/default.conf
COPY --from=web /web/dist /usr/share/nginx/html
