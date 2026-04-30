import { createRouter, createWebHistory } from 'vue-router';
import { registerRouterGuards } from './guards';
import { routes } from './routes';

export const router = createRouter({
  history: createWebHistory(),
  routes,
  scrollBehavior() {
    return {
      top: 0,
    };
  },
});

registerRouterGuards(router);
