import { Head, usePage } from '@inertiajs/react';
import { Moon, Sun } from 'lucide-react';
import { Avatar, IconButton, Text } from '@ninna-ui/primitives';
import { Container, HStack, VStack } from '@ninna-ui/layout';
import { useTheme } from '../lib/theme';

export default function PublicLayout({ title, children }) {
    const { brand } = usePage().props;
    const name = brand?.name || 'GIS Console';
    const { theme, toggle } = useTheme();

    return (
        <div className="min-h-screen bg-base-100 text-base-content">
            <Head title={title} />
            <header className="border-b border-border bg-base-50">
                <Container maxWidth="full">
                    <HStack align="center" justify="between">
                        <HStack align="center">
                            <Avatar name={name} shape="square" color="primary" />
                            <Text>{name}</Text>
                        </HStack>
                        <IconButton
                            variant="outline"
                            color="neutral"
                            aria-label={theme === 'dark' ? 'Switch to light mode' : 'Switch to dark mode'}
                            icon={theme === 'dark' ? <Sun className="h-full w-full" /> : <Moon className="h-full w-full" />}
                            onClick={toggle}
                        />
                    </HStack>
                </Container>
            </header>
            <main>
                <Container maxWidth="full">
                    <VStack>{children}</VStack>
                </Container>
            </main>
        </div>
    );
}
