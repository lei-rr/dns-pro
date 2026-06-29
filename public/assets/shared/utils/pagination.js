export function tablePagination(options = {}) {
  return {
    defaultPageSize: 20,
    showSizeChanger: true,
    pageSizeOptions: ['10', '20', '50', '100'],
    locale: { items_per_page: '' },
    ...options,
  }
}
