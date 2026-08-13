FROM node:22-alpine

WORKDIR /app
COPY --chown=node:node package.json LICENSE README.md ./
COPY --chown=node:node src ./src
COPY --chown=node:node public ./public
COPY --chown=node:node bin ./bin
COPY --chown=node:node config ./config
RUN mkdir -p /data && chown node:node /data

USER node
ENV HOST=0.0.0.0 PORT=8787 DATA_DIR=/data PROJECTS_FILE=/app/config/projects.json
VOLUME ["/data"]
EXPOSE 8787
CMD ["node", "src/server.mjs"]
