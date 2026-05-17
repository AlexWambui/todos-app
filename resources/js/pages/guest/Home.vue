<script setup lang="ts">
import { useForm, router } from '@inertiajs/vue3';
import { ref, computed, nextTick, watch } from 'vue';

interface TodoItem {
    id: number;
    title: string;
    priority: number;
    status: number;
    order: number;
    parent_id: number | null;
    todo_id: number;
    children?: TodoItem[];
}

interface Todo {
    id: number;
    title: string;
    color: string;
    icon: string;
    priority: number;
    status: number;
    todo_items?: TodoItem[];
}

const props = defineProps<{ todos: Todo[] }>();

// ─── State ────────────────────────────────────────────────────
const activeListId   = ref<number | null>(props.todos[0]?.id ?? null);
const filter         = ref<'all' | 'pending' | 'doing' | 'done' | 'urgent'>('all');
const expandedItems  = ref<Set<number>>(new Set());
const addingSubFor   = ref<number | null>(null);
const quickPriority  = ref(1);
const showNewList    = ref(false);
const newListColor   = ref('#7C6FF7');
const quickTaskTitle = ref('');
const subTaskTitle   = ref('');
const subInputRef    = ref<HTMLInputElement | null>(null);

const editingItemId = ref<number | null>(null);
const editingTitle = ref('');
const editInputRef = ref<HTMLInputElement | null>(null);

// ─── Config ───────────────────────────────────────────────────
const LIST_COLORS = [
    '#7C6FF7','#3AAD7A','#D4A020','#D44A4A',
    '#4A7FD4','#D4537E','#F97316','#14B8A6',
];

const PRIO = [
    { label: 'Low',    color: '#8F8FA3', bg: 'rgba(82,82,94,.18)',   dot: '#52525E' },
    { label: 'Medium', color: '#7AABF7', bg: 'rgba(74,127,212,.18)', dot: '#4A7FD4' },
    { label: 'High',   color: '#F5C842', bg: 'rgba(212,160,32,.18)', dot: '#D4A020' },
    { label: 'Urgent', color: '#F77A7A', bg: 'rgba(212,74,74,.18)',  dot: '#D44A4A' },
];

const STATUS = [
    { label: 'Pending',     color: '#8F8FA3', bg: 'rgba(90,90,114,.18)'  },
    { label: 'In Progress', color: '#7AABF7', bg: 'rgba(74,127,212,.18)' },
    { label: 'Done',        color: '#5CD4A0', bg: 'rgba(58,173,122,.18)' },
    { label: 'Archived',    color: '#8F8FA3', bg: 'rgba(90,90,114,.1)'   },
];

// ─── Computed ─────────────────────────────────────────────────
const activeList = computed(() =>
    props.todos.find(t => t.id === activeListId.value) ?? null
);

// Updated rootItems to sort children by order
const rootItems = computed(() => {
    const items = activeList.value?.todo_items ?? [];
    const rootItemsList = items.filter(i => i.parent_id === null);

    // Sort root items by order
    rootItemsList.sort((a, b) => (a.order || 0) - (b.order || 0));

    // Sort children of each root item
    rootItemsList.forEach(item => {
        if (item.children) {
            item.children.sort((a, b) => (a.order || 0) - (b.order || 0));
        }
    });

    return rootItemsList;
});

const filteredItems = computed(() => {
    const items = rootItems.value;
    let filtered = [...items];

    // Apply filters
    if (filter.value === 'pending') filtered = filtered.filter(i => i.status === 0);
    else if (filter.value === 'doing') filtered = filtered.filter(i => i.status === 1);
    else if (filter.value === 'done') filtered = filtered.filter(i => i.status === 2);
    else if (filter.value === 'urgent') filtered = filtered.filter(i => i.priority === 3);

    // Sort by order (lowest first)
    return filtered.sort((a, b) => (a.order || 0) - (b.order || 0));
});

const stats = computed(() => {
    // todo_items from the server = root tasks only (whereNull('parent_id')).
    // Each root task has .children = task items (layer 2).
    // Each task item has .children = sub-items (layer 3).
    // We MUST traverse the tree — NOT filter a flat array.
    const tasks = activeList.value?.todo_items ?? [];

    // ── Layer 1: root tasks ───────────────────────────────────
    const taskTotal = tasks.length;
    const taskDone  = tasks.filter(t => t.status === 2).length;
    const taskDoing = tasks.filter(t => t.status === 1).length;

    // ── Layer 2: task items (tasks[].children) ────────────────
    const taskItems: TodoItem[] = tasks.flatMap(t => t.children ?? []);
    const taskItemTotal = taskItems.length;
    const taskItemDone  = taskItems.filter(i => i.status === 2).length;
    const taskItemDoing = taskItems.filter(i => i.status === 1).length;

    // ── Layer 3: sub-items (tasks[].children[].children) ─────
    const subItems: TodoItem[] = taskItems.flatMap(i => i.children ?? []);
    const subItemTotal = subItems.length;
    const subItemDone  = subItems.filter(i => i.status === 2).length;

    // ── Smart overall progress ────────────────────────────────
    const grandTotal = taskTotal + taskItemTotal + subItemTotal;
    const grandDone  = taskDone  + taskItemDone  + subItemDone;
    const pct        = grandTotal ? Math.round(grandDone / grandTotal * 100) : 0;

    // ── Urgency ───────────────────────────────────────────────
    const urgent = tasks.filter(t => t.priority === 3 && t.status !== 2).length;

    return {
        taskTotal, taskDone, taskDoing,
        taskItemTotal, taskItemDone, taskItemDoing,
        subItemTotal, subItemDone,
        grandTotal, grandDone, pct,
        urgent,
    };
});

const pendingCount = (todo: Todo) =>
    (todo.todo_items ?? []).filter(i => i.parent_id === null && i.status !== 2 && i.status !== 3).length;

// ─── Forms ────────────────────────────────────────────────────
const todoForm = useForm({ title: '', color: '#7C6FF7' });

// ─── Actions ──────────────────────────────────────────────────
const selectList = (id: number) => {
    activeListId.value = id;
    filter.value = 'all';
    expandedItems.value.clear();
    addingSubFor.value = null;
};

const createList = () => {
    if (!todoForm.title.trim()) return;
    todoForm.color = newListColor.value;
    todoForm.post('/todos', {
        preserveScroll: true,
        onSuccess: () => {
            todoForm.reset();
            showNewList.value = false;
            // select the newly created list (it will be last in props.todos after reload)
            nextTick(() => {
                if (props.todos.length) {
                    activeListId.value = props.todos[props.todos.length - 1].id;
                }
            });
        },
    });
};

const addTask = () => {
    if (!activeList.value || !quickTaskTitle.value.trim()) return;
    router.post('/todo-items', {
        title:     quickTaskTitle.value.trim(),
        todo_id:   activeList.value.id,
        parent_id: null,
        priority:  quickPriority.value,
        status:    0,
    }, {
        preserveScroll: true,
        onSuccess: () => { quickTaskTitle.value = ''; },
    });
};

const addSubTask = (parentItem: TodoItem) => {
    if (!activeList.value || !subTaskTitle.value.trim()) return;
    router.post('/todo-items', {
        title:     subTaskTitle.value.trim(),
        todo_id:   activeList.value.id,
        parent_id: parentItem.id,
        priority:  1,
        status:    0,
    }, {
        preserveScroll: true,
        onSuccess: () => {
            subTaskTitle.value  = '';
            addingSubFor.value  = null;
            // keep expanded after adding
            expandedItems.value.add(parentItem.id);
        },
    });
};

const cycleStatus = (item: TodoItem) => {
    const next = item.status >= 2 ? 0 : item.status + 1;
    router.put(`/todo-items/${item.id}`, { status: next }, { preserveScroll: true });
};

const cyclePriority = (item: TodoItem) => {
    const next = (item.priority + 1) % 4;
    router.put(`/todo-items/${item.id}`, { priority: next }, { preserveScroll: true });
};

const deleteItem = (id: number) => {
    router.delete(`/todo-items/${id}`, { preserveScroll: true });
};

const toggleExpand = (id: number) => {
    if (expandedItems.value.has(id)) expandedItems.value.delete(id);
    else expandedItems.value.add(id);
};

const startAddSub = async (item: TodoItem) => {
    addingSubFor.value = item.id;
    expandedItems.value.add(item.id);
    await nextTick();
    subInputRef.value?.focus();
};

const cancelSub = () => {
    addingSubFor.value = null;
    subTaskTitle.value = '';
};

const cycleQPriority = () => {
    quickPriority.value = (quickPriority.value + 1) % 4;
};

const startEdit = async (item: TodoItem) => {
    editingItemId.value = item.id;
    editingTitle.value = item.title;
    await nextTick();
    editInputRef.value?.focus();
};

const cancelEdit = () => {
    editingItemId.value = null;
    editingTitle.value = '';
};

const updateItem = (item: TodoItem) => {
    if (!editingTitle.value.trim() || editingTitle.value === item.title) {
        cancelEdit();
        return;
    }

    router.put(`/todo-items/${item.id}`, {
        title: editingTitle.value.trim()
    }, {
        preserveScroll: true,
        onSuccess: () => {
            cancelEdit();
        },
    });
};

// new methods for sorting
const moveItemUp = (item: TodoItem, items: TodoItem[]) => {
    const currentIndex = items.findIndex(i => i.id === item.id);
    if (currentIndex === 0) return; // Already at top

    const prevItem = items[currentIndex - 1];
    const tempOrder = item.order;

    // Swap orders with the previous item
    router.put(`/todo-items/${item.id}`, { order: prevItem.order }, {
        preserveScroll: true,
        onSuccess: () => {
            router.put(`/todo-items/${prevItem.id}`, { order: tempOrder }, {
                preserveScroll: true,
            });
        },
    });
};

const moveItemDown = (item: TodoItem, items: TodoItem[]) => {
    const currentIndex = items.findIndex(i => i.id === item.id);
    if (currentIndex === items.length - 1) return; // Already at bottom

    const nextItem = items[currentIndex + 1];
    const tempOrder = item.order;

    // Swap orders with the next item
    router.put(`/todo-items/${item.id}`, { order: nextItem.order }, {
        preserveScroll: true,
        onSuccess: () => {
            router.put(`/todo-items/${nextItem.id}`, { order: tempOrder }, {
                preserveScroll: true,
            });
        },
    });
};

// Also add sorting for subtasks (they need their own items array)
const moveSubtaskUp = (subtask: TodoItem, parentItem: TodoItem) => {
    const items = parentItem.children || [];
    moveItemUp(subtask, items);
};

const moveSubtaskDown = (subtask: TodoItem, parentItem: TodoItem) => {
    const items = parentItem.children || [];
    moveItemDown(subtask, items);
};

// Auto-expand all items that have children whenever the active list changes
watch(activeListId, () => {
    // Clear existing expanded items
    expandedItems.value.clear();

    // Add all item IDs that have children
    const itemsWithChildren = (activeList.value?.todo_items ?? [])
        .filter(item => item.children && item.children.length > 0)
        .map(item => item.id);

    itemsWithChildren.forEach(id => expandedItems.value.add(id));
}, { immediate: true });
</script>

<template>
    <div class="app">
        <!-- ── SIDEBAR ── -->
        <aside class="sidebar">
            <div class="sidebar-header">
                <div class="logo">Aqilify <em>Todos</em></div>
            </div>

            <div class="sidebar-body">
                <p class="sidebar-label">My Lists</p>

                <button
                    v-for="todo in todos"
                    :key="todo.id"
                    class="list-btn"
                    :class="{ active: todo.id === activeListId }"
                    @click="selectList(todo.id)"
                >
                    <span class="list-dot" :style="{ background: todo.color }"></span>
                    <span class="list-name">{{ todo.title }}</span>
                    <span v-if="pendingCount(todo)" class="list-count">{{ pendingCount(todo) }}</span>
                </button>

                <!-- New list form -->
                <div v-if="showNewList" class="new-list-form">
                    <input
                        v-model="todoForm.title"
                        class="new-list-input"
                        placeholder="List name..."
                        @keydown.enter="createList"
                        @keydown.esc="showNewList = false"
                        autofocus
                    />
                    <div class="color-swatches">
                        <span
                            v-for="c in LIST_COLORS"
                            :key="c"
                            class="c-swatch"
                            :class="{ sel: c === newListColor }"
                            :style="{ background: c }"
                            @click="newListColor = c"
                        ></span>
                    </div>
                    <div class="new-list-actions">
                        <button class="btn-xs btn-accent" @click="createList" :disabled="todoForm.processing">
                            {{ todoForm.processing ? '…' : 'Add list' }}
                        </button>
                        <button class="btn-xs btn-ghost" @click="showNewList = false">Cancel</button>
                    </div>
                </div>

                <button v-if="!showNewList" class="add-list-btn" @click="showNewList = true">
                    <span>＋</span> New list
                </button>
            </div>
        </aside>

        <!-- ── MAIN ── -->
        <main class="main">

            <!-- Header -->
            <div class="main-header">
                <div v-if="activeList">
                    <div class="main-title-row">
                        <span class="header-dot" :style="{ background: activeList.color }"></span>
                        <h1 class="main-title">{{ activeList.title }}</h1>
                    </div>
                    <div class="header-meta">
                        <span class="meta-chip">{{ stats.taskTotal }} tasks &middot; {{ stats.taskItemTotal }} items &middot; {{ stats.subItemTotal }} sub-items</span>
                        <span class="meta-chip green">{{ stats.grandDone }}/{{ stats.grandTotal }} done overall</span>
                        <span v-if="stats.urgent" class="meta-chip red">{{ stats.urgent }} urgent</span>
                    </div>
                </div>
                <div v-else>
                    <h1 class="main-title" style="color: var(--text3)">Select a list to begin</h1>
                </div>
            </div>

            <!-- Stats -->
            <div v-if="activeList" class="stats-section">

                <!-- Smart progress bar -->
                <div class="progress-card">
                    <div class="progress-top">
                        <div>
                            <div class="progress-label">Overall Progress</div>
                            <div class="progress-sub">Derived from all tasks, items &amp; sub-items</div>
                        </div>
                        <div class="progress-pct">{{ stats.pct }}<span class="pct-sym">%</span></div>
                    </div>
                    <div class="progress-track">
                        <div
                            class="progress-fill"
                            :style="{
                                width: stats.pct + '%',
                                background: stats.pct >= 80 ? '#3AAD7A' : stats.pct >= 40 ? '#D4A020' : '#7C6FF7'
                            }"
                        ></div>
                    </div>
                    <div class="progress-breakdown">
                        <span class="pb-item">
                            <span class="pb-dot" style="background:#7C6FF7"></span>
                            {{ stats.taskDone }}/{{ stats.taskTotal }} tasks
                        </span>
                        <span class="pb-sep">·</span>
                        <span class="pb-item">
                            <span class="pb-dot" style="background:#4A7FD4"></span>
                            {{ stats.taskItemDone }}/{{ stats.taskItemTotal }} items
                        </span>
                        <span class="pb-sep">·</span>
                        <span class="pb-item">
                            <span class="pb-dot" style="background:#14B8A6"></span>
                            {{ stats.subItemDone }}/{{ stats.subItemTotal }} sub-items
                        </span>
                        <span class="pb-sep" style="margin-left:auto"></span>
                        <span class="pb-item" style="color:var(--text3)">{{ stats.grandDone }}/{{ stats.grandTotal }} total</span>
                    </div>
                </div>

                <!-- Stat cards row -->
                <div class="stats-row">
                    <div class="stat-card">
                        <div class="stat-icon" style="background:rgba(124,111,247,.15);color:#A89FF9">⬛</div>
                        <div class="stat-right">
                            <div class="stat-n">{{ stats.taskTotal }}</div>
                            <div class="stat-l">Root Tasks</div>
                            <div class="stat-sub">{{ stats.taskDone }} completed</div>
                        </div>
                        <div class="stat-ring">
                            <svg viewBox="0 0 36 36" width="36" height="36">
                                <circle cx="18" cy="18" r="14" fill="none" stroke="var(--surface3)" stroke-width="3"/>
                                <circle cx="18" cy="18" r="14" fill="none" stroke="#7C6FF7" stroke-width="3"
                                    stroke-dasharray="87.96"
                                    :stroke-dashoffset="stats.taskTotal ? 87.96 * (1 - stats.taskDone / stats.taskTotal) : 87.96"
                                    stroke-linecap="round" transform="rotate(-90 18 18)"/>
                            </svg>
                        </div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-icon" style="background:rgba(74,127,212,.15);color:#7AABF7">▦</div>
                        <div class="stat-right">
                            <div class="stat-n">{{ stats.taskItemTotal }}</div>
                            <div class="stat-l">Task Items</div>
                            <div class="stat-sub">{{ stats.taskItemDone }} completed</div>
                        </div>
                        <div class="stat-ring">
                            <svg viewBox="0 0 36 36" width="36" height="36">
                                <circle cx="18" cy="18" r="14" fill="none" stroke="var(--surface3)" stroke-width="3"/>
                                <circle cx="18" cy="18" r="14" fill="none" stroke="#4A7FD4" stroke-width="3"
                                    stroke-dasharray="87.96"
                                    :stroke-dashoffset="stats.taskItemTotal ? 87.96 * (1 - stats.taskItemDone / stats.taskItemTotal) : 87.96"
                                    stroke-linecap="round" transform="rotate(-90 18 18)"/>
                            </svg>
                        </div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-icon" style="background:rgba(20,184,166,.15);color:#5EEAD4">◈</div>
                        <div class="stat-right">
                            <div class="stat-n">{{ stats.subItemTotal }}</div>
                            <div class="stat-l">Sub-items</div>
                            <div class="stat-sub">{{ stats.subItemDone }} completed</div>
                        </div>
                        <div class="stat-ring">
                            <svg viewBox="0 0 36 36" width="36" height="36">
                                <circle cx="18" cy="18" r="14" fill="none" stroke="var(--surface3)" stroke-width="3"/>
                                <circle cx="18" cy="18" r="14" fill="none" stroke="#14B8A6" stroke-width="3"
                                    stroke-dasharray="87.96"
                                    :stroke-dashoffset="stats.subItemTotal ? 87.96 * (1 - stats.subItemDone / stats.subItemTotal) : 87.96"
                                    stroke-linecap="round" transform="rotate(-90 18 18)"/>
                            </svg>
                        </div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-icon" style="background:rgba(212,74,74,.15);color:#F77A7A">⚑</div>
                        <div class="stat-right">
                            <div class="stat-n">{{ stats.urgent }}</div>
                            <div class="stat-l">Urgent</div>
                            <div class="stat-sub">needs attention</div>
                        </div>
                        <div class="stat-ring">
                            <svg viewBox="0 0 36 36" width="36" height="36">
                                <circle cx="18" cy="18" r="14" fill="none" stroke="var(--surface3)" stroke-width="3"/>
                                <circle cx="18" cy="18" r="14" fill="none" stroke="#D44A4A" stroke-width="3"
                                    stroke-dasharray="87.96"
                                    :stroke-dashoffset="stats.taskTotal ? 87.96 * (1 - stats.urgent / stats.taskTotal) : 87.96"
                                    stroke-linecap="round" transform="rotate(-90 18 18)"/>
                            </svg>
                        </div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-icon" style="background:rgba(74,127,212,.15);color:#7AABF7">↻</div>
                        <div class="stat-right">
                            <div class="stat-n">{{ stats.taskDoing + stats.taskItemDoing }}</div>
                            <div class="stat-l">In Progress</div>
                            <div class="stat-sub">{{ stats.taskDoing }} tasks &middot; {{ stats.taskItemDoing }} items</div>
                        </div>
                        <div class="stat-ring">
                            <svg viewBox="0 0 36 36" width="36" height="36">
                                <circle cx="18" cy="18" r="14" fill="none" stroke="var(--surface3)" stroke-width="3"/>
                                <circle cx="18" cy="18" r="14" fill="none" stroke="#4A7FD4" stroke-width="3"
                                    stroke-dasharray="87.96"
                                    :stroke-dashoffset="stats.grandTotal ? 87.96 * (1 - (stats.taskDoing + stats.taskItemDoing) / stats.grandTotal) : 87.96"
                                    stroke-linecap="round" transform="rotate(-90 18 18)"/>
                            </svg>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Quick Add -->
            <div v-if="activeList" class="quick-add">
                <span class="quick-icon">○</span>
                <input
                    v-model="quickTaskTitle"
                    class="quick-input"
                    placeholder="Add a task… press Enter"
                    @keydown.enter="addTask"
                />
                <div class="quick-opts">
                    <button
                        class="prio-toggle"
                        :style="{ background: PRIO[quickPriority].bg, color: PRIO[quickPriority].color, borderColor: PRIO[quickPriority].dot }"
                        @click="cycleQPriority"
                        title="Cycle priority"
                    >
                        <span class="prio-dot-sm" :style="{ background: PRIO[quickPriority].dot }"></span>
                        {{ PRIO[quickPriority].label }}
                    </button>
                    <button class="add-task-btn" @click="addTask">Add task</button>
                </div>
            </div>

            <!-- Filters -->
            <div v-if="activeList" class="filters">
                <button class="filter-btn" :class="{ active: filter === 'all' }"     @click="filter = 'all'">All</button>
                <button class="filter-btn" :class="{ active: filter === 'pending' }" @click="filter = 'pending'">Pending</button>
                <button class="filter-btn" :class="{ active: filter === 'doing' }"   @click="filter = 'doing'">In Progress</button>
                <button class="filter-btn" :class="{ active: filter === 'done' }"    @click="filter = 'done'">Done</button>
                <span class="filter-sep"></span>
                <button class="filter-btn urgent" :class="{ active: filter === 'urgent' }" @click="filter = 'urgent'">🔴 Urgent</button>
            </div>

            <!-- Task List -->
            <div class="task-list">

                <!-- Empty: no list selected -->
                <div v-if="!activeList" class="empty">
                    <div class="empty-icon">📋</div>
                    <div class="empty-t">No list selected</div>
                    <div class="empty-s">Pick a list from the sidebar or create one</div>
                </div>

                <!-- Empty: list has no tasks -->
                <div v-else-if="filteredItems.length === 0" class="empty">
                    <div class="empty-icon">✅</div>
                    <div class="empty-t">Nothing here</div>
                    <div class="empty-s">{{ filter === 'all' ? 'Add your first task above' : 'No tasks match this filter' }}</div>
                </div>

                <!-- Tasks -->
                <div v-else class="task-wrap-list">
                    <div v-for="item in filteredItems" :key="item.id" class="task-wrap">
                        <div class="task-card" :class="{ done: item.status === 2 }">

                            <!-- Main row -->
                            <div class="task-row">
                                <!-- Expand toggle -->
                                <button
                                    v-if="item.children && item.children.length"
                                    class="expand-btn"
                                    @click="toggleExpand(item.id)"
                                >
                                    {{ expandedItems.has(item.id) ? '▾' : '▸' }}
                                </button>
                                <div v-else class="expand-spacer"></div>

                                <!-- Status circle -->
                                <button
                                    class="task-check"
                                    :class="{
                                        'check-done':  item.status === 2,
                                        'check-doing': item.status === 1,
                                    }"
                                    @click="cycleStatus(item)"
                                    :title="'Status: ' + STATUS[item.status]?.label"
                                ></button>

                                <!-- Title + tags -->
                                <div class="task-body">
                                    <!-- Edit mode -->
                                    <div v-if="editingItemId === item.id" class="edit-mode">
                                        <input
                                            ref="editInputRef"
                                            v-model="editingTitle"
                                            class="edit-input"
                                            @keydown.enter="updateItem(item)"
                                            @keydown.esc="cancelEdit"
                                            @blur="updateItem(item)"
                                        />
                                    </div>

                                    <!-- View mode -->
                                    <template v-else>
                                        <span class="task-title" :class="{ done: item.status === 2 }">{{ item.title }}</span>
                                        <span
                                            class="tag"
                                            :style="{ color: PRIO[item.priority]?.color, background: PRIO[item.priority]?.bg }"
                                        >{{ PRIO[item.priority]?.label }}</span>
                                        <span
                                            class="tag"
                                            :style="{ color: STATUS[item.status]?.color, background: STATUS[item.status]?.bg }"
                                        >{{ STATUS[item.status]?.label }}</span>
                                        <span
                                            v-if="item.children && item.children.length"
                                            class="sub-count"
                                        >{{ item.children.filter(c => c.status === 2).length }}/{{ item.children.length }}</span>
                                    </template>
                                </div>

                                <!-- Hover actions -->
                                <div class="task-actions">
                                    <button class="t-act move" @click="moveItemUp(item, filteredItems)" title="Move up">↑</button>
                                    <button class="t-act move" @click="moveItemDown(item, filteredItems)" title="Move down">↓</button>
                                    <button class="t-act" @click="startAddSub(item)" title="Add subtask">＋</button>
                                    <button class="t-act" @click="startEdit(item)" title="Edit">✎</button>
                                    <button
                                        class="t-act prio-act"
                                        @click="cyclePriority(item)"
                                        :title="'Priority: ' + PRIO[item.priority]?.label"
                                    >
                                        <span class="prio-dot-sm" :style="{ background: PRIO[item.priority]?.dot }"></span>
                                    </button>
                                    <button class="t-act del" @click="deleteItem(item.id)" title="Delete">✕</button>
                                </div>
                            </div>

                            <!-- Subtasks -->
                            <div
                                v-if="(expandedItems.has(item.id) && item.children && item.children.length) || addingSubFor === item.id"
                                class="subtasks"
                            >
                                <template v-if="expandedItems.has(item.id)">
                                    <div
                                        v-for="child in item.children"
                                        :key="child.id"
                                        class="subtask-row"
                                        :class="{ done: child.status === 2 }"
                                    >
                                        <button
                                            class="sub-check"
                                            :class="{
                                                'check-done': child.status === 2,
                                                'check-doing': child.status === 1,
                                            }"
                                            @click="cycleStatus(child)"
                                        ></button>

                                        <!-- Edit mode for subtask -->
                                        <div v-if="editingItemId === child.id" class="edit-mode subtask-edit">
                                            <input
                                                ref="editInputRef"
                                                v-model="editingTitle"
                                                class="edit-input subtask-edit-input"
                                                @keydown.enter="updateItem(child)"
                                                @keydown.esc="cancelEdit"
                                                @blur="updateItem(child)"
                                            />
                                        </div>

                                    <!-- View mode for subtask -->
                                    <template v-else>
                                        <span class="sub-title" :class="{ done: child.status === 2 }">{{ child.title }}</span>
                                        <span
                                            class="tag"
                                            :style="{ color: PRIO[child.priority]?.color, background: PRIO[child.priority]?.bg, fontSize: '10px' }"
                                        >{{ PRIO[child.priority]?.label }}</span>
                                    </template>

                                    <div class="subtask-actions">
                                        <button class="t-act move-small" @click="moveSubtaskUp(child, item)" title="Move up">↑</button>
                                        <button class="t-act move-small" @click="moveSubtaskDown(child, item)" title="Move down">↓</button>
                                        <button class="t-act" @click="startEdit(child)" title="Edit">✎</button>
                                        <button class="t-act del sub-del" @click="deleteItem(child.id)">✕</button>
                                    </div>
                                </div>
                                </template>

                                <!-- Inline subtask add -->
                                <div v-if="addingSubFor === item.id" class="inline-add">
                                    <div class="sub-check" style="opacity:.35;cursor:default"></div>
                                    <input
                                        ref="subInputRef"
                                        v-model="subTaskTitle"
                                        class="inline-input"
                                        placeholder="Subtask name…"
                                        @keydown.enter="addSubTask(item)"
                                        @keydown.esc="cancelSub"
                                    />
                                    <button class="btn-xs btn-accent" @click="addSubTask(item)">Add</button>
                                    <button class="btn-xs btn-ghost" @click="cancelSub">Cancel</button>
                                </div>
                            </div>

                        </div><!-- /task-card -->
                    </div><!-- /task-wrap -->
                </div><!-- /task-wrap-list -->
            </div><!-- /task-list -->
        </main>
    </div>
</template>

<style scoped>
@import url('https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wght@12..96,400;12..96,600;12..96,700&family=DM+Sans:wght@300;400;500&display=swap');

/* ── Variables ───────────────────────────────────────── */
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

.app {
    --bg:       #0F0F11;
    --surface:  #18181C;
    --surface2: #222228;
    --surface3: #2A2A32;
    --border:   #2E2E38;
    --border2:  #3A3A48;
    --text:     #F0EFF5;
    --text2:    #9B9AAD;
    --text3:    #5A5A72;
    --accent:   #7C6FF7;
    --accent-l: #A89FF9;
    --accent-d: #5046C8;
    --accent-bg:rgba(124,111,247,.12);
    --green:    #3AAD7A;
    --green-l:  #5CD4A0;
    --green-bg: rgba(58,173,122,.12);
    --red:      #D44A4A;
    --red-l:    #F77A7A;
    --red-bg:   rgba(212,74,74,.12);
    --r:        10px;
    --r-sm:     6px;
    --r-lg:     16px;
    --fh:       'Bricolage Grotesque', sans-serif;
    --fb:       'DM Sans', sans-serif;

    display: grid;
    grid-template-columns: 256px 1fr;
    min-height: 100vh;
    background: var(--bg);
    color: var(--text);
    font-family: var(--fb);
    font-size: 14px;
}

/* ── Sidebar ─────────────────────────────────────────── */
.sidebar {
    background: var(--surface);
    border-right: 1px solid var(--border);
    display: flex;
    flex-direction: column;
    min-height: 100vh;
}
.sidebar-header {
    padding: 22px 20px 16px;
    border-bottom: 1px solid var(--border);
}
.logo {
    font-family: var(--fh);
    font-size: 20px;
    font-weight: 700;
    color: var(--text);
    letter-spacing: -.3px;
}
.logo em { color: var(--accent-l); font-style: normal; }

.sidebar-body {
    padding: 14px 12px 12px;
    flex: 1;
    display: flex;
    flex-direction: column;
    gap: 2px;
}
.sidebar-label {
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: .7px;
    color: var(--text3);
    padding: 0 8px;
    margin-bottom: 6px;
}

.list-btn {
    width: 100%;
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 9px 10px;
    border-radius: var(--r-sm);
    border: none;
    background: transparent;
    color: var(--text2);
    cursor: pointer;
    font-family: var(--fb);
    font-size: 13.5px;
    font-weight: 400;
    transition: background .15s, color .15s;
    text-align: left;
}
.list-btn:hover  { background: var(--surface2); color: var(--text); }
.list-btn.active { background: var(--accent-bg); color: var(--accent-l); }

.list-dot  { width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0; }
.list-name { flex: 1; }
.list-count {
    font-size: 11px; font-weight: 600;
    background: var(--surface3); color: var(--text3);
    padding: 2px 7px; border-radius: 10px;
}
.list-btn.active .list-count { background: rgba(124,111,247,.2); color: var(--accent-l); }

.add-list-btn {
    display: flex; align-items: center; gap: 8px;
    padding: 9px 10px; margin-top: 4px;
    border-radius: var(--r-sm);
    border: 1px dashed var(--border2);
    background: transparent; color: var(--text3);
    cursor: pointer; font-family: var(--fb); font-size: 13px;
    transition: all .15s;
}
.add-list-btn:hover { border-color: var(--accent); color: var(--accent-l); background: var(--accent-bg); }

.new-list-form {
    background: var(--surface2);
    border: 1px solid var(--border2);
    border-radius: var(--r);
    padding: 12px;
    margin-top: 4px;
    display: flex; flex-direction: column; gap: 10px;
}
.new-list-input {
    background: transparent; border: none; outline: none;
    color: var(--text); font-family: var(--fb); font-size: 14px;
    width: 100%;
}
.new-list-input::placeholder { color: var(--text3); }

.color-swatches { display: flex; gap: 6px; flex-wrap: wrap; }
.c-swatch {
    width: 20px; height: 20px; border-radius: 50%;
    cursor: pointer; border: 2px solid transparent;
    transition: border .15s; flex-shrink: 0;
}
.c-swatch.sel { border-color: #fff; }

.new-list-actions { display: flex; gap: 6px; }

/* ── Buttons ─────────────────────────────────────────── */
.btn-xs {
    font-family: var(--fb); font-size: 12px; font-weight: 500;
    border: none; border-radius: var(--r-sm);
    padding: 5px 12px; cursor: pointer; transition: all .15s;
}
.btn-accent { background: var(--accent); color: #fff; }
.btn-accent:hover { background: var(--accent-d); }
.btn-ghost { background: var(--surface3); color: var(--text2); border: 1px solid var(--border2); }
.btn-ghost:hover { color: var(--text); }
.btn-accent:disabled { opacity: .5; cursor: not-allowed; }

/* ── Main ────────────────────────────────────────────── */
.main {
    display: flex; flex-direction: column;
    overflow-y: auto; min-height: 100vh;
}

.main-header { padding: 28px 32px 0; }
.main-title-row { display: flex; align-items: center; gap: 12px; }
.header-dot { width: 14px; height: 14px; border-radius: 50%; flex-shrink: 0; }
.main-title { font-family: var(--fh); font-size: 26px; font-weight: 700; color: var(--text); letter-spacing: -.3px; }
.header-meta { display: flex; align-items: center; gap: 8px; margin-top: 10px; padding-left: 26px; }
.meta-chip {
    font-size: 12px; font-weight: 500; padding: 3px 10px; border-radius: 20px;
    background: rgba(255,255,255,.06); color: var(--text3);
}
.meta-chip.green { background: var(--green-bg); color: var(--green-l); }
.meta-chip.red   { background: var(--red-bg);   color: var(--red-l);   }

/* ── Stats ───────────────────────────────────────────── */
.stats-section { padding: 20px 32px 0; display: flex; flex-direction: column; gap: 10px; }

/* Progress card */
.progress-card {
    background: var(--surface); border: 1px solid var(--border);
    border-radius: var(--r-lg); padding: 18px 20px;
}
.progress-top {
    display: flex; align-items: flex-start;
    justify-content: space-between; margin-bottom: 12px;
}
.progress-label { font-family: var(--fh); font-size: 15px; font-weight: 600; color: var(--text); }
.progress-sub { font-size: 11px; color: var(--text3); margin-top: 2px; }
.progress-pct { font-family: var(--fh); font-size: 32px; font-weight: 700; color: var(--text); line-height: 1; }
.pct-sym { font-size: 16px; font-weight: 400; color: var(--text3); }
.progress-track {
    height: 8px; background: var(--surface3); border-radius: 8px;
    overflow: hidden; margin-bottom: 12px;
}
.progress-fill { height: 100%; border-radius: 8px; transition: width .5s ease, background .5s ease; }
.progress-breakdown {
    display: flex; align-items: center; gap: 8px; flex-wrap: wrap;
}
.pb-item { display: flex; align-items: center; gap: 5px; font-size: 12px; color: var(--text2); }
.pb-dot { width: 6px; height: 6px; border-radius: 50%; flex-shrink: 0; }
.pb-sep { color: var(--text3); font-size: 12px; }

/* Stat cards row */
.stats-row {
    display: grid; grid-template-columns: repeat(5, 1fr);
    gap: 8px;
}
.stat-card {
    background: var(--surface); border: 1px solid var(--border);
    border-radius: var(--r); padding: 14px 14px 12px;
    display: flex; align-items: flex-start; gap: 10px; position: relative;
    overflow: hidden;
}
.stat-icon {
    width: 30px; height: 30px; border-radius: 8px; flex-shrink: 0;
    display: flex; align-items: center; justify-content: center;
    font-size: 14px; margin-top: 1px;
}
.stat-right { flex: 1; min-width: 0; }
.stat-n {
    font-family: var(--fh); font-size: 22px; font-weight: 700;
    color: var(--text); line-height: 1.1;
}
.stat-l {
    font-size: 11px; font-weight: 600; color: var(--text2);
    text-transform: uppercase; letter-spacing: .4px; margin-top: 2px;
}
.stat-sub { font-size: 11px; color: var(--text3); margin-top: 3px; }
.stat-ring {
    position: absolute; bottom: 10px; right: 10px; opacity: .9;
}
.stat-ring circle { transition: stroke-dashoffset .5s ease; }

/* ── Quick Add ───────────────────────────────────────── */
.quick-add {
    display: flex; align-items: center; gap: 0;
    margin: 20px 32px 0;
    background: var(--surface); border: 1px solid var(--border); border-radius: var(--r);
    overflow: hidden; transition: border-color .15s;
}
.quick-add:focus-within { border-color: var(--accent); }
.quick-icon { padding: 0 14px; color: var(--text3); font-size: 18px; flex-shrink: 0; }
.quick-input {
    flex: 1; background: transparent; border: none; outline: none;
    color: var(--text); font-family: var(--fb); font-size: 14px; padding: 14px 0;
}
.quick-input::placeholder { color: var(--text3); }
.quick-opts {
    display: flex; align-items: center; gap: 6px;
    padding: 0 10px; border-left: 1px solid var(--border);
}
.prio-toggle {
    display: flex; align-items: center; gap: 5px;
    padding: 5px 10px; border-radius: var(--r-sm);
    border: 1px solid transparent; cursor: pointer;
    font-family: var(--fb); font-size: 12px; font-weight: 500;
    transition: all .15s; white-space: nowrap;
}
.add-task-btn {
    background: var(--accent); color: #fff; border: none;
    border-radius: var(--r-sm); padding: 7px 16px;
    font-family: var(--fb); font-size: 13px; font-weight: 500;
    cursor: pointer; margin: 6px 0; white-space: nowrap;
    transition: background .15s;
}
.add-task-btn:hover { background: var(--accent-d); }

/* ── Filters ─────────────────────────────────────────── */
.filters {
    display: flex; align-items: center; gap: 6px; flex-wrap: wrap;
    padding: 14px 32px 0;
}
.filter-btn {
    background: transparent; border: 1px solid var(--border); border-radius: 20px;
    padding: 5px 14px; font-size: 12px; font-weight: 500; color: var(--text3);
    cursor: pointer; font-family: var(--fb); transition: all .15s;
}
.filter-btn:hover, .filter-btn.active {
    border-color: var(--accent); color: var(--accent-l); background: var(--accent-bg);
}
.filter-btn.urgent.active { border-color: var(--red); color: var(--red-l); background: var(--red-bg); }
.filter-sep { width: 1px; height: 14px; background: var(--border); margin: 0 2px; }

/* ── Task List ───────────────────────────────────────── */
.task-list { padding: 16px 32px 60px; flex: 1; }
.task-wrap-list { display: flex; flex-direction: column; gap: 6px; }

.task-wrap { }
.task-card {
    background: var(--surface); border: 1px solid var(--border);
    border-radius: var(--r); overflow: hidden;
    transition: border-color .15s;
}
.task-card:hover { border-color: var(--border2); }
.task-card.done { opacity: .55; }

/* ── Task Row ────────────────────────────────────────── */
.task-row {
    display: flex; align-items: center;
    padding: 0 12px; min-height: 52px;
}
.task-row:hover .task-actions { opacity: 1; }

.expand-btn {
    width: 22px; height: 22px; border-radius: 4px; border: none;
    background: transparent; cursor: pointer;
    color: var(--text3); font-size: 11px;
    display: flex; align-items: center; justify-content: center;
    transition: background .15s, color .15s;
    flex-shrink: 0; margin-right: 4px;
}
.expand-btn:hover { background: var(--surface2); color: var(--text); }
.expand-spacer { width: 26px; flex-shrink: 0; }

.task-check {
    width: 20px; height: 20px; border-radius: 50%;
    border: 2px solid var(--border2); flex-shrink: 0;
    cursor: pointer; background: transparent;
    display: flex; align-items: center; justify-content: center;
    transition: all .2s; margin-right: 12px;
}
.task-check:hover { border-color: var(--green); }
.task-check.check-done {
    background: var(--green); border-color: var(--green);
}
.task-check.check-done::after { content: '✓'; color: #fff; font-size: 11px; font-weight: 700; }
.task-check.check-doing, .sub-check.check-doing { border-color: #4A7FD4; border-style: dashed; }

.task-body {
    flex: 1; min-width: 0;
    display: flex; align-items: center; gap: 8px;
    overflow: hidden;
}
.task-title {
    font-size: 14px; font-weight: 400; color: var(--text);
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
    transition: color .15s;
}
.task-title.done { text-decoration: line-through; color: var(--text3); }

.tag {
    font-size: 11px; font-weight: 600;
    padding: 2px 8px; border-radius: 10px; flex-shrink: 0;
}
.sub-count { font-size: 11px; color: var(--text3); flex-shrink: 0; }

.task-actions {
    display: flex; align-items: center; gap: 4px;
    margin-left: 8px; opacity: 0; transition: opacity .15s;
}
.t-act {
    width: 27px; height: 27px; border-radius: 5px;
    border: 1px solid var(--border); background: transparent;
    cursor: pointer; display: flex; align-items: center; justify-content: center;
    font-size: 13px; color: var(--text3); transition: all .15s;
}
.t-act:hover { border-color: var(--border2); color: var(--text); background: var(--surface2); }
.t-act.del:hover { border-color: var(--red); color: var(--red-l); background: var(--red-bg); }
.prio-dot-sm { width: 8px; height: 8px; border-radius: 50%; display: inline-block; flex-shrink: 0; }

/* ── Subtasks ────────────────────────────────────────── */
.subtasks { border-top: 1px solid var(--border); }

.subtask-row {
    display: flex; align-items: center; gap: 10px;
    padding: 0 12px 0 48px; min-height: 40px;
    border-bottom: 1px solid var(--border);
    transition: background .15s;
}
.subtask-row:last-child { border-bottom: none; }
.subtask-row:hover { background: var(--surface2); }
.subtask-row:hover .t-act { opacity: 1; }
.subtask-row .t-act { opacity: 0; }
.subtask-row.done { opacity: .55; }

.sub-check {
    width: 16px; height: 16px; border-radius: 50%;
    border: 2px solid var(--border2); flex-shrink: 0;
    cursor: pointer; background: transparent;
    display: flex; align-items: center; justify-content: center;
    transition: all .2s;
}
.sub-check:hover { border-color: var(--green); }
.sub-check.check-done { background: var(--green); border-color: var(--green); }
.sub-check.check-done::after { content: '✓'; color: #fff; font-size: 9px; font-weight: 700; }

.sub-title {
    flex: 1; font-size: 13px; color: var(--text2);
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.sub-title.done { text-decoration: line-through; font-style: italic; color: var(--text); }

.sub-del { width: 22px; height: 22px; font-size: 11px; }

/* ── Inline Add ──────────────────────────────────────── */
.inline-add {
    display: flex; align-items: center; gap: 8px;
    padding: 8px 12px 8px 48px;
    border-top: 1px solid var(--border);
    background: var(--surface2);
}
.inline-input {
    flex: 1; background: transparent; border: none; outline: none;
    color: var(--text); font-family: var(--fb); font-size: 13px;
}
.inline-input::placeholder { color: var(--text3); }

/* ── Empty ───────────────────────────────────────────── */
.empty { text-align: center; padding: 80px 20px; }
.empty-icon { font-size: 40px; margin-bottom: 14px; opacity: .5; }
.empty-t { font-family: var(--fh); font-size: 18px; font-weight: 600; color: var(--text2); margin-bottom: 6px; }
.empty-s { font-size: 14px; color: var(--text3); }

/* ── Scrollbar ───────────────────────────────────────── */
.main::-webkit-scrollbar { width: 4px; }
.main::-webkit-scrollbar-track { background: transparent; }
.main::-webkit-scrollbar-thumb { background: var(--border2); border-radius: 4px; }

/* Edit mode styles */
.edit-mode {
    flex: 1;
    min-width: 0;
}

.edit-input {
    width: 100%;
    background: var(--surface2);
    border: 1px solid var(--accent);
    border-radius: var(--r-sm);
    padding: 4px 8px;
    color: var(--text);
    font-family: var(--fb);
    font-size: 14px;
    outline: none;
}

.edit-input:focus {
    border-color: var(--accent-l);
}

.subtask-edit {
    flex: 1;
}

.subtask-edit-input {
    font-size: 13px;
    padding: 2px 6px;
}

/* Make edit button visible */
.t-act {
    /* existing styles */
}

.t-act:hover {
    /* existing styles */
}

/* Add these styles to your <style> section */

/* Move button specific styles */
.move {
    font-size: 14px;
    font-weight: bold;
}

.move-small {
    font-size: 12px;
    font-weight: bold;
    width: 24px;
    height: 24px;
}

/* Container for subtask actions */
.subtask-actions {
    display: flex;
    align-items: center;
    gap: 4px;
    opacity: 0;
    transition: opacity .15s;
}

.subtask-row:hover .subtask-actions {
    opacity: 1;
}

/* Adjust subtask-row to accommodate new actions */
.subtask-row {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 0 12px 0 48px;
    min-height: 40px;
    border-bottom: 1px solid var(--border);
    transition: background .15s;
}

/* Ensure the subtask title still takes available space */
.subtask-row .sub-title {
    flex: 1;
}
</style>
