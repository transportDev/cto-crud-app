# Pin a compatible MySQL 8.0.x, install tzdata, and add a sanitized config file
FROM mysql:8.0

# Install tzdata to silence timezone loading warnings
RUN microdnf install -y tzdata && microdnf clean all

# Copy custom MySQL configuration with secure permissions
COPY my.cnf /etc/mysql/conf.d/custom.cnf
RUN chown root:root /etc/mysql/conf.d/custom.cnf && chmod 0644 /etc/mysql/conf.d/custom.cnf

# No ENTRYPOINT/CMD override; inherit from base image