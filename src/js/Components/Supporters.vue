<template>
  <div class="buymecoffee_main_container">
    <div class="buymecoffee_supporters input_fade_border">
      <el-row>
        <el-col :span="12">
          <h1 class="buymecoffee_menu_title">Supporters</h1>
        </el-col>
        <el-col :span="12">
        </el-col>
      </el-row>
      <div v-if="!loading" class="h-20 mt-5 flex flex-wrap gap-2.5 justify-around buymecoffee_supporters_stat_row">
          <div class="buymecoffee_supporters_stat buymecoffee_status_paid">
            <div>
              <p class="buymecoffee_supporters_stat_count">{{statusReportData?.total_paid}} / {{statusReportData?.total}} of Total</p>
              <p class="buymecoffee_supporters_stat_status">Paid Transactions </p>
            </div>
          </div>
          <div class="buymecoffee_supporters_stat buymecoffee_status_pending">
            <div>
              <p class="buymecoffee_supporters_stat_count">{{statusReportData?.total_pending}}</p>
              <p class="buymecoffee_supporters_stat_status">Pending Transactions</p>
            </div>
          </div>
          <div class="buymecoffee_supporters_stat buymecoffee_status_failed">
            <div>
              <p class="buymecoffee_supporters_stat_count">{{statusReportData?.total_failed}}</p>
              <p class="buymecoffee_supporters_stat_status">Failed Transactions</p>
            </div>
          </div>
        <div class="buymecoffee_supporters_stat buymecoffee_status_refunded">
          <div>
            <p class="buymecoffee_supporters_stat_count">{{statusReportData?.total_refunded}}</p>
            <p class="buymecoffee_supporters_stat_status ">Refunded Transactions</p>
          </div>
        </div>

      </div>
      <div class="pt-4 pb-4 flex align-left gap-2.5 float-end">
        <div>
            <el-select v-model="searchStatus" id="filter" style="width: 120px" @change="getSupporters">
              <el-option v-for="filter in filters" :key="filter.value" :value="filter.value">{{filter.label}}</el-option>
            </el-select>
        </div>
        <div>
          <el-input v-model="search" style="width: 240px" placeholder="Search" @change="getSupporters" @keyup.enter="getSupporters"></el-input>
        </div>
      </div>
    <SupportersTable
        :fetching="fetching"
        @pageChanged="(val)=>{current = val; getSupporters()}"
        @sizeChanged="(val) => {posts_per_page = val; getSupporters()}"
        @fetchSupporters="()=>getSupporters()"
        :supporters="supporters"
        :posts_per_page="posts_per_page"
        :current="current"
        :total="total"/>
    </div>
  </div>
</template>
<script setup>
</script>
<script>
import SupportersTable from "./SupportersTable.vue";
export default {
  name: "Supporters",
  data() {
    return {
      current: 0,
      total: 0,
      search: '',
      searchStatus: 'all',
      loading: false,
      posts_per_page: 10,
      supporters: [],
      filters: [
        {label: 'All', value: 'all'},
        {label: 'Paid', value: 'paid'},
        {label: 'Pending', value: 'pending'},
        {label: 'Cancelled', value: 'cancelled'},
        {label: 'Refunded', value: 'refunded'},
        {label: 'Failed', value: 'failed'},
      ]
    }
  },
  components: {
    SupportersTable,
  },
  methods: {
    getSupporters () {
      this.fetching = true;
      this.$get({
        action: 'buymecoffee_admin_ajax',
        route: 'get_supporters',
        data: {
          search: this.search,
          filter_status: this.searchStatus,
          limit: this.limit,
          page: this.current,
          posts_per_page: this.posts_per_page,
        },
        statusReportData:{
        },
        buymecoffee_nonce: window.BuyMeCoffeeAdmin.buymecoffee_nonce
      })
          .then((response) => {
            this.supporters = response?.data?.supporters;
            this.total = response?.data?.total;
            this.reportData = response?.data?.reports;
            this.fetching = false;
          })
          .fail(error => {
            this.$message.error(error?.responseJSON?.data?.message);
          })
          .always(() => {
            this.fetching = false;
          });

    },
    statusReport() {
      this.loading = true;
      this.$get({
        action: 'buymecoffee_admin_ajax',
        route: 'status_report',
        buymecoffee_nonce: window.BuyMeCoffeeAdmin.buymecoffee_nonce
      }).then((response) => {
        this.statusReportData = response.data;
      }).catch((e) => {
        this.$handleError(e)
      }).always(() => {
        this.loading = false;
      });
    }
  },
  mounted() {
    this.getSupporters();
    this.statusReport();
  }
}
</script>

<style scoped lang="scss">
.buymecoffee_supporters {
  box-shadow: rgb(17 12 46 / 15%) 0px 8px 100px 0px;
  background: #f6fffc;
  padding: 24px;
  border-radius: 6px;
  min-height: 500px;
  .buymecoffee_supporters_stat {
    text-align: center;
    display: flex;
    height: 100%;
    flex-direction: column;
    gap: 14px;
    border-radius: 8px;
    --tw-border-opacity: 1;
    border: none;
    --tw-bg-opacity: 1;
    padding: 20px 20px 15px;
    box-shadow: rgba(17, 12, 46, 0.15) 0px 8px 100px 14px;
    &_row{
      display: grid;
      gap: 16px;
      grid-template-columns: repeat(4, minmax(0, 1fr));
    }
    &_status{
      font-size: 12px;
      text-transform: uppercase;
      color: #909090;
      position: relative;
      bottom: -7px;
      font-family: monospace;
    }
    &_count {
      font-size: 23px;
      font-family: cursive;
      color: #000000;
    }
  }
}
</style>