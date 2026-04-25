<script>
import {Coffee, User, Money} from '@element-plus/icons-vue';
export default {
  name: 'Report',
  components: {
    Coffee,
    User,
    Money
  },
  data () {
    return {
      val: 'Hello from Report'
    }
  },
  props: {
    reportData: {
      type: Object,
      required: true
    }
  },
  methods: {
    getImage(path) {
      return window.BuyMeCoffeeAdmin.assets_url + 'images/' + path;
    },
    stripHtml(str) {
      if (!str) {
        return '';
      }
      const div = document.createElement('div');
      div.innerHTML = str;
      return div.textContent || div.innerText || '';
    }
  }
}

</script>

<template>
  <div class="buymecoffee_report_wrapper">
    <div class="buymecoffee_report_inner">
      <div class="buymecoffee_report_count" style="text-align:center;">
        <img :src="getImage('money.png')" alt="Money"/>
        <ul v-if="reportData.currency_total.length > 0" style="max-height: 120px;overflow-y: hidden;">
          <li>
            <span>Total Received</span>
          </li>
          <p style="margin: 0;"
             v-if="reportData.currency_total.length === 1"
             >{{ stripHtml(reportData?.currency_total[0].formatted_total) }}</p>
          <li v-else v-for="group in reportData.currency_total">
            <span style="font-size:20px;">{{ stripHtml(group.formatted_total) }}</span>
          </li>
        </ul>
        <span v-else><br/>No amount received yet!</span>
      </div>
<!--      <div class="buymecoffee_report_header">-->
<!--        <p>Amount Received</p>-->
<!--      </div>-->
    </div>
    <div class="buymecoffee_report_inner">
      <div class="buymecoffee_report_count" style="text-align:center;">
        <img :src="getImage('money-pending.png')" alt="MoneyPending"/>
        <ul v-if="reportData.currency_total_pending.length > 0" style="max-height: 120px;overflow-y: hidden;">
          <li>
            <span>Amount Pending</span>
          </li>
          <p style="margin: 0;"
             v-if="reportData.currency_total_pending.length === 1"
             >{{ stripHtml(reportData?.currency_total_pending[0].formatted_total) }}</p>
          <li v-else v-for="group in reportData.currency_total_pending">
            <span style="font-size:20px;">{{ stripHtml(group.formatted_total) }}</span>
          </li>
        </ul>
        <span v-else><br/>No pending yet!</span>
      </div>
    </div>
      <div class="buymecoffee_report_inner">
        <div class="buymecoffee_report_count">
          <img :src="getImage('supporters.png')" alt="supporters"/>
          <ul>
            <li>
              <span>Supporters</span>
            </li>
            <li>
              <p style="margin:0">{{ reportData.total_supporters || 0 }} </p>
            </li>
          </ul>
        </div>
<!--        <div class="buymecoffee_report_header">-->
<!--          <p>Supporters</p>-->
<!--        </div>-->
      </div>
      <div class="buymecoffee_report_inner">
        <div class="buymecoffee_report_count">
          <img :src="getImage('coffee-cup.png')" alt="coffee-cup"/>
          <ul>
            <li>
              <span>Total Coffee</span>
            </li>
            <li>
              <p style="margin: 0;">{{reportData.total_coffee || 0}} </p>
            </li>
          </ul>
        </div>

<!--        <div class="buymecoffee_report_header">-->
<!--          <p>Total Coffee</p>-->
<!--        </div>-->
      </div>
  </div>
</template>

<style scoped lang="scss">
.buymecoffee_report_wrapper {
  background: linear-gradient(84deg, #f5fffe54, #affbf054);
  justify-content: space-between;
  flex-wrap: wrap;
  box-shadow: rgb(17 12 46 / 15%) 0px 8px 100px 0px;
  margin-bottom: 24px;
  border-radius: 7px;
  min-height: 190px;
  display: flex;
  align-items: center;
}

.buymecoffee_report_inner {
  width: 23%;
  min-width: 250px;
  &:last-child {
    border: none;
  }
}

.buymecoffee_report_header {
  font-size: 16px;
  font-family: monospace;
  text-align: center;
}

.buymecoffee_report_count p {
  font-size: 28px;
  text-align: center;
}
.buymecoffee_report_count i {
  margin-right: 20px;
  color: #2a9e6f;
}
@media only screen and (min-width: 840px) {
  .buymecoffee_report_inner {
    border-right: 1px solid #ccc;
  }
}
</style>