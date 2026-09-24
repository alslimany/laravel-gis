import { Head, Link, usePage } from '@inertiajs/react';
import { Moon, Sun } from 'lucide-react';
import { Avatar, Heading, IconButton, List, ListItem, Text } from '@ninna-ui/primitives';
import { Container, HStack, VStack } from '@ninna-ui/layout';
import { useTheme } from '../lib/theme';

export default function GuestLayout({ title, children }) {
    const { brand, routes } = usePage().props;
    const name = brand?.name || 'GIS Console';
    const named = name !== 'GIS Console';
    const onLogin = typeof window !== 'undefined' && window.location.pathname === '/login';
    const onRegister = typeof window !== 'undefined' && window.location.pathname === '/register';
    const { theme, toggle } = useTheme();

    return (
        <div className="grid min-h-screen bg-base-100 text-base-content lg:grid-cols-[minmax(280px,420px)_1fr]">
            <Head title={title} />
            <aside className="border-b border-border bg-base-50 lg:border-b-0 lg:border-r">
                <Container maxWidth="full">
                    <VStack align="start">
                        <Avatar name={name} shape="square" color="primary" />
                        <Heading as="h1">{name}</Heading>
                        <Text muted>{named ? 'Maps, layers, and imports stay with this organization.' : 'Maps, layers, and imports stay here.'}</Text>
                        <List>
                            <ListItem>Import datasets</ListItem>
                            <ListItem>Publish layers</ListItem>
                            <ListItem>Compose maps</ListItem>
                        </List>
                    </VStack>
                </Container>
            </aside>
            <main className="flex items-center justify-center">
                <Container maxWidth="sm">
                    <VStack>
                        <HStack justify="end">
                            <IconButton
                                variant="outline"
                                color="neutral"
                                aria-label={theme === 'dark' ? 'Switch to light mode' : 'Switch to dark mode'}
                                icon={theme === 'dark' ? <Sun className="h-full w-full" /> : <Moon className="h-full w-full" />}
                                onClick={toggle}
                            />
                        </HStack>
                        {children}
                        <HStack>
                            {routes.login && !onLogin ? (
                                <Link href={routes.login} className="text-primary hover:underline">
                                    Sign in
                                </Link>
                            ) : null}
                            {routes.register && !onRegister ? (
                                <Link href={routes.register} className="text-base-content/70 hover:text-base-content">
                                    Register
                                </Link>
                            ) : null}
                        </HStack>
                    </VStack>
                </Container>
            </main>
        </div>
    );
}
