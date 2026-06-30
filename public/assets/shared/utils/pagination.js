export function tablePagination(options = {}) {
  return {
    defaultPageSize: 20,
    showSizeChanger: true,
    pageSizeOptions: ['10', '20', '50', '100'],
    locale: { items_per_page: '' },
    ...options,
  }
}

export function paginationState(defaults = {}) {
  return {
    page: Number(defaults.page) || 1,
    per_page: Number(defaults.per_page) || 20,
    total: Number(defaults.total) || 0,
  }
}

export function nextPaginationState(current, pagination) {
  const nextPerPage = Number(pagination?.pageSize) || current.per_page || 20
  const pageSizeChanged = nextPerPage !== current.per_page
  const nextPage = pageSizeChanged ? 1 : (Number(pagination?.current) || 1)

  if (nextPage === current.page && nextPerPage === current.per_page) {
    return null
  }

  return {
    ...current,
    page: nextPage,
    per_page: nextPerPage,
  }
}

export function mergePaginationMeta(current, meta = {}, fallback = {}) {
  return {
    page: Number(meta.page) || Number(fallback.page) || current.page || 1,
    per_page: Number(meta.per_page) || Number(fallback.per_page) || current.per_page || 20,
    total: Number(meta.total) || 0,
  }
}
