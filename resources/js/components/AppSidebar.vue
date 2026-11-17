<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { Coins, History, ShieldCheck, Sparkles } from '@lucide/vue';
import { computed } from 'vue';
import AppLogo from '@/components/AppLogo.vue';
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import type { NavItem } from '@/types';

const page = usePage();

// Literal paths: some of these routes live in other slices (contracts/http.md).
const mainNavItems = computed<NavItem[]>(() => [
    { title: 'Generate', href: '/generate', icon: Sparkles },
    { title: 'History', href: '/generations', icon: History },
    { title: 'Credits', href: '/credits', icon: Coins },
    ...(page.props.auth.user?.is_admin
        ? [{ title: 'Admin', href: '/admin/report', icon: ShieldCheck }]
        : []),
]);
</script>

<template>
    <Sidebar collapsible="icon" variant="inset">
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link href="/generate">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent>
            <NavMain :items="mainNavItems" />
        </SidebarContent>

        <SidebarFooter>
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
