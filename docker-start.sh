#!/bin/bash
# =============================================================================
# CREATIVE TREES - DOCKER STARTUP SCRIPT
# =============================================================================
# Usage: ./docker-start.sh
# =============================================================================

set -e

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
CYAN='\033[0;36m'
NC='\033[0m' # No Color

echo -e "${CYAN}"
echo "╔═══════════════════════════════════════════════════════════════════════════╗"
echo "║           🌲 CREATIVE TREES - ENTERPRISE DOCKER STARTUP 🌲                 ║"
echo "╚═══════════════════════════════════════════════════════════════════════════╝"
echo -e "${NC}"

# Check if Docker is running
if ! docker info >/dev/null 2>&1; then
    echo -e "${RED}❌ Docker is not running. Please start Docker first.${NC}"
    exit 1
fi

echo -e "${BLUE}📦 Starting all services...${NC}"
echo ""

# Build and start all containers
docker compose up -d --build

echo ""
echo -e "${GREEN}✅ All services started successfully!${NC}"
echo ""
echo -e "${CYAN}═══════════════════════════════════════════════════════════════════════════${NC}"
echo -e "${YELLOW}📌 SERVICE ACCESS URLS:${NC}"
echo -e "${CYAN}═══════════════════════════════════════════════════════════════════════════${NC}"
echo ""
echo -e "  ${GREEN}🌐 Application:${NC}     http://localhost:8080"
echo -e "  ${GREEN}📧 Mailpit:${NC}         http://localhost:8025"
echo -e "  ${GREEN}🗄️  phpMyAdmin:${NC}      http://localhost:8888"
echo -e "  ${GREEN}📊 Grafana:${NC}         http://localhost:3000"
echo -e "  ${GREEN}📈 Prometheus:${NC}      http://localhost:9090"
echo -e "  ${GREEN}🔴 Redis Insight:${NC}   http://localhost:5540"
echo -e "  ${GREEN}⚡ Vite Dev:${NC}        http://localhost:5173"
echo ""
echo -e "${CYAN}═══════════════════════════════════════════════════════════════════════════${NC}"
echo -e "${YELLOW}🔐 CREDENTIALS:${NC}"
echo -e "${CYAN}═══════════════════════════════════════════════════════════════════════════${NC}"
echo ""
echo -e "  ${BLUE}Grafana:${NC}"
echo -e "    Username: admin"
echo -e "    Password: creativetrees_grafana_2026"
echo ""
echo -e "  ${BLUE}phpMyAdmin:${NC}"
echo -e "    Server:   mysql"
echo -e "    Username: app"
echo -e "    Password: (check .env file for DB_PASSWORD)"
echo ""
echo -e "  ${BLUE}Redis Insight:${NC}"
echo -e "    Host:     redis"
echo -e "    Port:     6379"
echo -e "    Password: creativetrees_redis_secure_2026"
echo ""
echo -e "${CYAN}═══════════════════════════════════════════════════════════════════════════${NC}"
echo -e "${YELLOW}💡 USEFUL COMMANDS:${NC}"
echo -e "${CYAN}═══════════════════════════════════════════════════════════════════════════${NC}"
echo ""
echo -e "  ${BLUE}View logs:${NC}          docker compose logs -f"
echo -e "  ${BLUE}Stop all:${NC}           docker compose down"
echo -e "  ${BLUE}Restart:${NC}            docker compose restart"
echo -e "  ${BLUE}Laravel artisan:${NC}    docker compose exec app php artisan"
echo -e "  ${BLUE}Run migrations:${NC}     docker compose exec app php artisan migrate"
echo -e "  ${BLUE}Clear cache:${NC}        docker compose exec app php artisan optimize:clear"
echo ""
echo -e "${GREEN}🚀 Happy coding with Creative Trees!${NC}"
echo ""
