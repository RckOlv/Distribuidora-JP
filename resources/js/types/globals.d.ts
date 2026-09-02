interface ZiggyRouter {
    current(name?: string): boolean;
    name(): string | undefined;
    params: Record<string, unknown>;
}

declare function route(): ZiggyRouter;
declare function route(
    name: string,
    params?: Record<string, unknown> | string | number,
    absolute?: boolean,
): string;

interface Window {
    axios: import('axios').AxiosInstance;
}