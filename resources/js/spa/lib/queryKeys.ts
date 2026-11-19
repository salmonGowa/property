/** True when a query key is exactly the given detail key, e.g. ['leases', 'detail', 7]. */
export function isDetailOf(queryKey: readonly unknown[], detailKey: readonly unknown[]): boolean {
    return queryKey.length === detailKey.length && detailKey.every((part, index) => queryKey[index] === part);
}
